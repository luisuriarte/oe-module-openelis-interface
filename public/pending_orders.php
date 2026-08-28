<?php

/**
 * Pending lab orders page — send orders to OpenELIS on demand.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Resolve the OpenEMR root by walking up until globals.php is found.
// Works both in the module (dev) and when copied to <root>/public/modules/<name>/ (prod).
// Peers with the same resolver in admin_mapping.php and send_order_action.php.
//
// This deployment keeps OpenEMR's globals.php under <root>/interface/globals.php
// (the OpenEMR web root is <root>/interface/), while the module's web scripts
// are copied to the sibling <root>/public/modules/<name>/. Because public/ and
// interface/ are siblings, we check BOTH "<dir>/globals.php" and
// "<dir>/interface/globals.php" at each level of the upward walk.
$__oeRoot = __DIR__;
$__found = null;
for ($i = 0; $i < 15; $i++) {
    $__probeRoot = $__oeRoot . '/globals.php';
    $__probeIface = $__oeRoot . '/interface/globals.php';
    if (file_exists($__probeRoot) || is_file($__probeRoot) || (realpath($__probeRoot) !== false)) {
        $__found = $__oeRoot;
        break;
    }
    if (file_exists($__probeIface) || is_file($__probeIface) || (realpath($__probeIface) !== false)) {
        $__found = $__oeRoot . '/interface';
        break;
    }
    $parent = dirname($__oeRoot);
    if ($parent === $__oeRoot) {
        break;
    }
    $__oeRoot = $parent;
}
if ($__found === null) {
    $__guesses = [];
    foreach ([dirname(__DIR__, 3), dirname(__DIR__, 4), dirname(__DIR__, 5)] as $__g) {
        $__guesses[] = $__g . '/interface';
        $__guesses[] = $__g;
    }
    foreach ($__guesses as $__g) {
        if (file_exists($__g . '/globals.php')) {
            $__found = $__g;
            break;
        }
    }
}
if ($__found === null) {
    error_log("OpenELIS ERROR: could not locate globals.php from __DIR__=" . __DIR__);
    die('OpenEMR root not found');
}
require_once $__found . '/globals.php';
unset($__found, $__oeRoot, $__probeRoot, $__probeIface, $__g, $__guesses);
unset($__oeRoot);

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// ── CSRF compatibility wrapper (OpenEMR 8.0 vs 8.2+) ───────────────────
function oe_module_csrf_collect(string $subject = 'default'): string
{
    $r = new ReflectionMethod(CsrfUtils::class, 'collectCsrfToken');
    $p = $r->getParameters()[0];
    if ($p->hasType() && $p->getType()->getName() === 'Symfony\Component\HttpFoundation\Session\SessionInterface') {
        $session = \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession();
        return CsrfUtils::collectCsrfToken($session, $subject);
    }
    return CsrfUtils::collectCsrfToken($subject);
}

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('Access denied');
    exit;
}

$perPage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$filter = $_GET['filter'] ?? 'pending'; // pending | all | sent | error

// ── Build query ─────────────────────────────────────────────────────────

$where = "po.activity = 1";
$params = [];

if ($filter === 'pending') {
    $where .= " AND po.mod_openelis_sync_status IS NULL AND po.date_transmitted IS NULL";
} elseif ($filter === 'sent') {
    $where .= " AND po.mod_openelis_sync_status = 'sent'";
} elseif ($filter === 'error') {
    $where .= " AND po.mod_openelis_sync_status = 'error'";
}
// 'all' = no extra filter

if ($search !== '') {
    $where .= " AND (pd.fname LIKE ? OR pd.lname LIKE ? OR pd.pubpid LIKE ? OR po.procedure_order_id LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
}

$countSql = "FROM procedure_order po
    INNER JOIN patient_data pd ON po.patient_id = pd.pid
    LEFT JOIN procedure_providers pp ON po.lab_id = pp.ppid
    WHERE $where";

$countRow = sqlQuery("SELECT COUNT(*) AS total $countSql", $params);
$total = (int)($countRow['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$dataSql = "SELECT po.procedure_order_id, po.date_ordered, po.order_priority, po.order_status,
                   po.mod_openelis_sync_status, po.date_transmitted, po.control_id,
                   po.patient_id, po.lab_id,
                   pd.pubpid, pd.fname, pd.lname,
                   pp.name AS provider_name
    FROM procedure_order po
    INNER JOIN patient_data pd ON po.patient_id = pd.pid
    LEFT JOIN procedure_providers pp ON po.lab_id = pp.ppid
    WHERE $where
    ORDER BY po.date_ordered DESC
    LIMIT ? OFFSET ?";

$rows = [];
$rs = sqlStatement($dataSql, array_merge($params, [$perPage, $offset]));
while ($row = sqlFetchArray($rs)) {
    // Count tests for this order
    $codeCount = sqlQuery(
        "SELECT COUNT(*) AS total FROM procedure_order_code WHERE procedure_order_id = ? AND do_not_send = 0",
        [$row['procedure_order_id']]
    );
    $row['test_count'] = (int)($codeCount['total'] ?? 0);

    // Count how many tests have LOINC codes mapped
    $mappedCount = sqlQuery(
        "SELECT COUNT(*) AS total
         FROM procedure_order_code poc
         INNER JOIN mod_openelis_code_mapping m ON poc.procedure_code = m.openemr_procedure_code
         WHERE poc.procedure_order_id = ? AND poc.do_not_send = 0
           AND m.is_active = 1 AND m.loinc_code IS NOT NULL AND m.loinc_code != ''",
        [$row['procedure_order_id']]
    );
    $row['mapped_count'] = (int)($mappedCount['total'] ?? 0);

    $rows[] = $row;
}

$csrfToken = oe_module_csrf_collect();
$webRoot = $GLOBALS['webroot'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt("OpenELIS Pending Orders"); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .filter-tabs { margin-bottom: 1rem; }
        .filter-tabs .nav-link { padding: 0.3rem 0.8rem; font-size: 0.9em; }
        .badge-mapping-ok { background-color: #198754; }
        .badge-mapping-partial { background-color: #ffc107; color: #333; }
        .badge-mapping-none { background-color: #dc3545; }
        .btn-send { min-width: 80px; }
        .spinner-sm { width: 1rem; height: 1rem; border-width: 0.15em; }
        .result-ok { color: #198754; font-weight: bold; }
        .result-error { color: #dc3545; font-weight: bold; }
        .sync-status { font-size: 0.85em; }
        .order-id { font-family: monospace; }
    </style>
</head>
<body class="container-fluid">
    <div class="row mt-3 mb-3">
        <div class="col">
            <h3><?php echo xlt("OpenELIS Pending Orders"); ?></h3>
            <p class="text-muted">
                <?php echo xlt("Send lab orders to OpenELIS on demand. Only orders with complete code mappings can be sent."); ?>
            </p>
        </div>
    </div>

    <!-- Filter tabs -->
    <ul class="nav nav-tabs filter-tabs">
        <?php
        $filters = [
            'pending' => xlt("Pending"),
            'all' => xlt("All Orders"),
            'sent' => xlt("Sent"),
            'error' => xlt("Errors"),
        ];
        foreach ($filters as $key => $label):
            $paramsFilter = ['filter' => $key];
            if ($search !== '') {
                $paramsFilter['search'] = $search;
            }
        ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $filter === $key ? 'active' : ''; ?>"
                   href="pending_orders.php?<?php echo http_build_query($paramsFilter); ?>">
                    <?php echo $label; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Search -->
    <div class="row mt-2 mb-3">
        <div class="col-md-8 col-lg-6">
            <form method="get" class="input-group">
                <input type="hidden" name="filter" value="<?php echo attr($filter); ?>">
                <input type="text" class="form-control" name="search"
                       placeholder="<?php echo attr("Search by patient name, ID, or order #..."); ?>"
                       value="<?php echo attr($search); ?>">
                <button type="submit" class="btn btn-primary"><?php echo xlt("Search"); ?></button>
                <?php if ($search !== ''): ?>
                    <a href="pending_orders.php?filter=<?php echo attr($filter); ?>"
                       class="btn btn-outline-secondary"><?php echo xlt("Clear"); ?></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Orders table -->
    <?php if (empty($rows)): ?>
        <div class="alert alert-light border">
            <?php
            if ($filter === 'pending') {
                echo xlt("No pending orders to send.");
            } else {
                echo xlt("No orders found.");
            }
            ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th><?php echo xlt("Order #"); ?></th>
                        <th><?php echo xlt("Date"); ?></th>
                        <th><?php echo xlt("Patient"); ?></th>
                        <th><?php echo xlt("Lab"); ?></th>
                        <th><?php echo xlt("Tests"); ?></th>
                        <th><?php echo xlt("Mapping"); ?></th>
                        <th><?php echo xlt("Priority"); ?></th>
                        <th><?php echo xlt("Status"); ?></th>
                        <th class="text-end"><?php echo xlt("Action"); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $orderId = (int)$row['procedure_order_id'];
                    $canSend = $row['mapped_count'] > 0
                        && $row['test_count'] === $row['mapped_count']
                        && ($row['mod_openelis_sync_status'] ?? '') !== 'sent';
                    $syncStatus = $row['mod_openelis_sync_status'] ?? '';
                ?>
                    <tr id="order-row-<?php echo $orderId; ?>">
                        <td class="order-id"><?php echo text($orderId); ?></td>
                        <td><?php echo $row['date_ordered'] ? text(oeFormatShortDate($row['date_ordered'])) : '—'; ?></td>
                        <td>
                            <?php echo text($row['fname'] . ' ' . $row['lname']); ?>
                            <br><small class="text-muted"><?php echo text($row['pubpid']); ?></small>
                        </td>
                        <td><?php echo text($row['provider_name'] ?? ''); ?></td>
                        <td><?php echo text($row['test_count']); ?></td>
                        <td>
                            <?php if ($row['mapped_count'] === $row['test_count'] && $row['test_count'] > 0): ?>
                                <span class="badge badge-mapping-ok"><?php echo xlt("Complete"); ?></span>
                            <?php elseif ($row['mapped_count'] > 0): ?>
                                <span class="badge badge-mapping-partial">
                                    <?php echo text($row['mapped_count']) . '/' . text($row['test_count']); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-mapping-none"><?php echo xlt("None"); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo text(ucfirst($row['order_priority'] ?? 'routine')); ?></td>
                        <td class="sync-status">
                            <?php if ($syncStatus === 'sent'): ?>
                                <span class="result-ok"><?php echo xlt("Sent"); ?></span>
                                <br><small><?php echo text($row['date_transmitted']); ?></small>
                            <?php elseif ($syncStatus === 'error'): ?>
                                <span class="result-error"><?php echo xlt("Error"); ?></span>
                            <?php elseif (!empty($row['date_transmitted'])): ?>
                                <span class="text-muted"><?php echo xlt("Transmitted (HL7)"); ?></span>
                            <?php else: ?>
                                <span class="text-muted"><?php echo xlt("Pending"); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($canSend): ?>
                                <button type="button" class="btn btn-sm btn-success btn-send"
                                        data-order-id="<?php echo $orderId; ?>"
                                        onclick="sendToOpenELIS(this, <?php echo $orderId; ?>)">
                                    <?php echo xlt("Send"); ?>
                                </button>
                            <?php elseif ($syncStatus === 'sent'): ?>
                                <button type="button" class="btn btn-sm btn-outline-success" disabled>
                                    <?php echo xlt("Sent"); ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                        title="<?php echo attr("Complete code mapping required"); ?>">
                                    <?php echo xlt("Send"); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination pagination-sm justify-content-center">
                    <?php
                    $pagParams = ['filter' => $filter];
                    if ($search !== '') {
                        $pagParams['search'] = $search;
                    }

                    // Previous
                    if ($page > 1) {
                        $pagParams['page'] = $page - 1;
                        echo '<li class="page-item"><a class="page-link" href="pending_orders.php?' . http_build_query($pagParams) . '">' . xlt("Previous") . '</a></li>';
                    } else {
                        echo '<li class="page-item disabled"><span class="page-link">' . xlt("Previous") . '</span></li>';
                    }

                    // Page numbers
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++) {
                        $pagParams['page'] = $i;
                        if ($i === $page) {
                            echo '<li class="page-item active"><span class="page-link">' . text($i) . '</span></li>';
                        } else {
                            echo '<li class="page-item"><a class="page-link" href="pending_orders.php?' . http_build_query($pagParams) . '">' . text($i) . '</a></li>';
                        }
                    }

                    // Next
                    if ($page < $totalPages) {
                        $pagParams['page'] = $page + 1;
                        echo '<li class="page-item"><a class="page-link" href="pending_orders.php?' . http_build_query($pagParams) . '">' . xlt("Next") . '</a></li>';
                    } else {
                        echo '<li class="page-item disabled"><span class="page-link">' . xlt("Next") . '</span></li>';
                    }
                    ?>
                </ul>
            </nav>
        <?php endif; ?>

        <p class="text-muted small mt-2">
            <?php echo xlt("Showing"); ?>
            <?php echo text($total); ?>
            <?php echo xlt("orders"); ?>
            (<?php echo text($page); ?>/<?php echo text($totalPages); ?>)
        </p>
    <?php endif; ?>

<script>
function sendToOpenELIS(btn, orderId) {
    if (!confirm(<?php echo xlj("Send this order to OpenELIS?"); ?>)) {
        return;
    }

    var originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-sm" role="status"></span> ' + <?php echo xlj("Sending..."); ?>;
    btn.classList.remove('btn-success');
    btn.classList.add('btn-warning');

    var formData = new FormData();
    formData.append('order_id', orderId);
    formData.append('csrf_token_form', <?php echo js_escape($csrfToken); ?>);
    formData.append('action', 'send');

    fetch(<?php echo js_escape($webRoot . '/public/modules/openelis/send_order_action.php'); ?>, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(function(response) {
        if (!response.ok) {
            // Non-2xx: try to parse JSON, else surface the HTTP status
            return response.json().catch(function() {
                throw new Error('HTTP ' + response.status);
            });
        }
        return response.json();
    })
    .then(function(data) {
        var row = document.getElementById('order-row-' + orderId);
        var statusCell = row.querySelector('.sync-status');

        if (data.success) {
            btn.innerHTML = '✓ ' + <?php echo xlj("Sent"); ?>;
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-success');
            statusCell.innerHTML = '<span class="result-ok"><?php echo xla("Sent to OpenELIS"); ?></span>';
        } else {
            btn.innerHTML = '✗ ' + <?php echo xlj("Error"); ?>;
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-danger');
            statusCell.innerHTML = '<span class="result-error" title="' + data.message.replace(/"/g, '&quot;') + '"><?php echo xla("Error"); ?></span>';

            setTimeout(function() {
                btn.disabled = false;
                btn.innerHTML = originalText;
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-success');
            }, 5000);
        }
    })
    .catch(function(err) {
        btn.innerHTML = '✗ Error';
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-danger');
        // Surface the actual error detail (HTTP status or parse/net error)
        alert('Error de envío: ' + (err && err.message ? err.message : 'desconocido'));
        console.error('OpenELIS send error:', err);

        setTimeout(function() {
            btn.disabled = false;
            btn.innerHTML = originalText;
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-success');
        }, 5000);
    });
}
</script>
</body>
</html>
