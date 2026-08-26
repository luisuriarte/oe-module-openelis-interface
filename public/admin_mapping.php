<?php

/**
 * Admin page for mapping OpenEMR procedure codes to OpenELIS test IDs.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once dirname(__FILE__, 5) . '/globals.php';

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

function oe_module_csrf_verify(?string $token, string $subject = 'default'): bool
{
    $r = new ReflectionMethod(CsrfUtils::class, 'verifyCsrfToken');
    $p = $r->getParameters();
    if (count($p) >= 2 && $p[1]->hasType() && $p[1]->getType()->getName() === 'Symfony\Component\HttpFoundation\Session\SessionInterface') {
        $session = \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession();
        return CsrfUtils::verifyCsrfToken($token, $session, $subject);
    }
    return CsrfUtils::verifyCsrfToken($token, $subject);
}

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo xlt('Access denied');
    exit;
}

$perPage = 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page_unmapped = max(1, (int)($_GET['page_unmapped'] ?? 1));
$page_mapped = max(1, (int)($_GET['page_mapped'] ?? 1));

// ── Process POST actions ────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!oe_module_csrf_verify($_POST['csrf_token_form'] ?? '', 'OpenElisModule')) {
        CsrfUtils::csrfNotVerified();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_mapping') {
        $procedureCode = trim($_POST['procedure_code'] ?? '');
        $procedureName = trim($_POST['procedure_name'] ?? '');
        $elisTestId = trim($_POST['openelis_test_id'] ?? '');
        $elisTestName = trim($_POST['openelis_test_name'] ?? '');
        $loincCode = trim($_POST['loinc_code'] ?? '');
        $snomedSpecimen = trim($_POST['snomed_specimen'] ?? '');
        $snomedFinding = trim($_POST['snomed_finding'] ?? '');
        $units = trim($_POST['units'] ?? '');

        if ($procedureCode !== '' && $elisTestId !== '') {
            $sql = "INSERT INTO mod_openelis_code_mapping
                        (openemr_procedure_code, openemr_procedure_name, openelis_test_id, openelis_test_name,
                         loinc_code, snomed_specimen, snomed_finding, units, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ON DUPLICATE KEY UPDATE
                        openemr_procedure_name = VALUES(openemr_procedure_name),
                        openelis_test_id = VALUES(openelis_test_id),
                        openelis_test_name = VALUES(openelis_test_name),
                        loinc_code = VALUES(loinc_code),
                        snomed_specimen = VALUES(snomed_specimen),
                        snomed_finding = VALUES(snomed_finding),
                        units = VALUES(units),
                        is_active = 1";
            sqlStatement($sql, [
                $procedureCode, $procedureName, $elisTestId, $elisTestName,
                $loincCode ?: null, $snomedSpecimen ?: null, $snomedFinding ?: null, $units ?: null
            ]);
        }

        $searchParam = $search !== '' ? '&search=' . attr_url($search) : '';
        header('Location: admin_mapping.php?saved=1' . $searchParam);
        exit;
    }

    if ($action === 'toggle_active') {
        $mappingId = (int)($_POST['mapping_id'] ?? 0);
        if ($mappingId > 0) {
            $sql = "UPDATE mod_openelis_code_mapping SET is_active = NOT is_active WHERE id = ?";
            sqlStatement($sql, [$mappingId]);
        }

        $searchParam = $search !== '' ? '&search=' . attr_url($search) : '';
        header('Location: admin_mapping.php?toggled=1' . $searchParam);
        exit;
    }
}

// ── Search parameters ───────────────────────────────────────────────────

$whereExtra = '';
$paramsExtra = [];
if ($search !== '') {
    $whereExtra = " AND (pt.name LIKE ? OR pt.procedure_code LIKE ? OR pt.standard_code LIKE ?)";
    $like = '%' . $search . '%';
    $paramsExtra = [$like, $like, $like];
}

// ── Pagination counts ───────────────────────────────────────────────────

$countBase = "FROM procedure_type pt
    LEFT JOIN mod_openelis_code_mapping m ON pt.procedure_code = m.openemr_procedure_code
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord'" . $whereExtra;

$countUnmapped = sqlQuery(
    "SELECT COUNT(*) AS total " . $countBase . " AND m.id IS NULL",
    $paramsExtra
);
$totalUnmapped = (int)($countUnmapped['total'] ?? 0);
$totalPagesUnmapped = max(1, (int)ceil($totalUnmapped / $perPage));

$countMapped = sqlQuery(
    "SELECT COUNT(*) AS total " . $countBase . " AND m.id IS NOT NULL",
    $paramsExtra
);
$totalMapped = (int)($countMapped['total'] ?? 0);
$totalPagesMapped = max(1, (int)ceil($totalMapped / $perPage));

// ── Data: unmapped procedures ───────────────────────────────────────────

$offsetUnmapped = ($page_unmapped - 1) * $perPage;
$rsUnmapped = sqlStatement(
    "SELECT pt.procedure_code, pt.name, pt.standard_code
    FROM procedure_type pt
    LEFT JOIN mod_openelis_code_mapping m ON pt.procedure_code = m.openemr_procedure_code
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord' AND m.id IS NULL" . $whereExtra . "
    ORDER BY pt.name
    LIMIT ? OFFSET ?",
    array_merge($paramsExtra, [$perPage, $offsetUnmapped])
);

$unmapped = [];
while ($row = sqlFetchArray($rsUnmapped)) {
    $unmapped[] = $row;
}

// ── Data: mapped procedures ─────────────────────────────────────────────

$offsetMapped = ($page_mapped - 1) * $perPage;
$rsMapped = sqlStatement(
    "SELECT pt.procedure_code, pt.name, pt.standard_code,
            m.id AS mapping_id, m.openelis_test_id, m.openelis_test_name,
            m.loinc_code, m.snomed_specimen, m.snomed_finding, m.units, m.is_active
    FROM procedure_type pt
    INNER JOIN mod_openelis_code_mapping m ON pt.procedure_code = m.openemr_procedure_code
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord'" . $whereExtra . "
    ORDER BY pt.name
    LIMIT ? OFFSET ?",
    array_merge($paramsExtra, [$perPage, $offsetMapped])
);

$mapped = [];
while ($row = sqlFetchArray($rsMapped)) {
    $mapped[] = $row;
}

$csrfToken = oe_module_csrf_collect('OpenElisModule');
$webRoot = $GLOBALS['webroot'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt("OpenELIS Code Mapping"); ?></title>
    <?php Header::setupHeader(['common']); ?>
    <style>
        .mapping-section { margin-bottom: 2rem; }
        .badge-active { background-color: #198754; color: #fff; }
        .badge-inactive { background-color: #dc3545; color: #fff; }
        .form-inline-row { display: none; }
        .form-inline-row.open { display: table-row; }
        .standard-code { font-size: 0.85em; color: #6c757d; }
        .code-badge { font-size: 0.78em; padding: 2px 6px; }
        .btn-code-finder { border-start-width: 0; }
    </style>
</head>
<body class="container-fluid">
    <div class="row mt-3 mb-3">
        <div class="col">
            <h3><?php echo xlt("OpenEMR to OpenELIS Code Mapping"); ?></h3>
            <p class="text-muted">
                <?php echo xlt("Map OpenEMR lab procedures to OpenELIS tests."); ?>
            </p>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo xlt("Mapping saved successfully."); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['toggled'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?php echo xlt("Mapping status updated."); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="row mb-3">
        <div class="col-md-8 col-lg-6">
            <form method="get" class="input-group">
                <input type="text" class="form-control" name="search"
                       placeholder="<?php echo attr("Search by name, code, or standard..."); ?>"
                       value="<?php echo attr($search); ?>">
                <button type="submit" class="btn btn-primary"><?php echo xlt("Search"); ?></button>
                <?php if ($search !== ''): ?>
                    <a href="admin_mapping.php" class="btn btn-outline-secondary"><?php echo xlt("Clear"); ?></a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- ── Unmapped Procedures ──────────────────────────────────────── -->
    <div class="mapping-section">
        <h5>
            <?php echo xlt("Unmapped Procedures"); ?>
            <span class="badge bg-secondary"><?php echo text($totalUnmapped); ?></span>
        </h5>

        <?php if (empty($unmapped)): ?>
            <div class="alert alert-light border">
                <?php echo xlt("No unmapped procedures found."); ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt("Code"); ?></th>
                            <th><?php echo xlt("Name"); ?></th>
                            <th><?php echo xlt("Standard"); ?></th>
                            <th class="text-end"><?php echo xlt("Action"); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($unmapped as $row): ?>
                        <tr id="unmapped-<?php echo attr($row['procedure_code']); ?>">
                            <td><code><?php echo text($row['procedure_code']); ?></code></td>
                            <td><?php echo text($row['name']); ?></td>
                            <td class="standard-code">
                                <?php echo $row['standard_code'] !== '' ? text($row['standard_code']) : '—'; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="toggleRow('unmapped-<?php echo attr($row['procedure_code']); ?>-form')">
                                    <?php echo xlt("Assign"); ?>
                                </button>
                            </td>
                        </tr>
                        <tr class="form-inline-row" id="unmapped-<?php echo attr($row['procedure_code']); ?>-form">
                            <td colspan="4">
                                <form method="post" class="p-2 border rounded bg-light">
                                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="action" value="save_mapping">
                                    <input type="hidden" name="procedure_code" value="<?php echo attr($row['procedure_code']); ?>">
                                    <input type="hidden" name="procedure_name" value="<?php echo attr($row['name']); ?>">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("OpenELIS Test ID"); ?></label>
                                            <input type="text" class="form-control form-control-sm" name="openelis_test_id" required
                                                   placeholder="<?php echo attr("e.g., 42"); ?>">
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("OpenELIS Test Name"); ?></label>
                                            <input type="text" class="form-control form-control-sm" name="openelis_test_name"
                                                   placeholder="<?php echo attr("e.g., Glucose"); ?>">
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("LOINC Code"); ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="loinc_code" id="loinc-<?php echo attr($row['procedure_code']); ?>"
                                                       placeholder="<?php echo attr("e.g., 2345-7"); ?>">
                                                <button type="button" class="btn btn-outline-secondary btn-code-finder"
                                                        onclick="openCodeFinder('LOINC', 'loinc-<?php echo attr($row['procedure_code']); ?>')"
                                                        title="<?php echo attr("Search LOINC codes"); ?>">🔍</button>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("SNOMED Specimen"); ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="snomed_specimen" id="snomed-sp-<?php echo attr($row['procedure_code']); ?>"
                                                       placeholder="<?php echo attr("e.g., 119297000"); ?>">
                                                <button type="button" class="btn btn-outline-secondary btn-code-finder"
                                                        onclick="openCodeFinder('SNOMED-CT', 'snomed-sp-<?php echo attr($row['procedure_code']); ?>')"
                                                        title="<?php echo attr("Search SNOMED codes"); ?>">🔍</button>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("Units"); ?></label>
                                            <input type="text" class="form-control form-control-sm" name="units"
                                                   placeholder="<?php echo attr("e.g., mg/dL"); ?>" style="width:80px;">
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-sm btn-success"><?php echo xlt("Save"); ?></button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    onclick="toggleRow('unmapped-<?php echo attr($row['procedure_code']); ?>-form')">
                                                <?php echo xlt("Cancel"); ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php renderPagination('page_unmapped', $page_unmapped, $totalPagesUnmapped, $search); ?>
        <?php endif; ?>
    </div>

    <!-- ── Configured Mappings ──────────────────────────────────────── -->
    <div class="mapping-section">
        <h5>
            <?php echo xlt("Configured Mappings"); ?>
            <span class="badge bg-secondary"><?php echo text($totalMapped); ?></span>
        </h5>

        <?php if (empty($mapped)): ?>
            <div class="alert alert-light border">
                <?php echo xlt("No configured mappings found."); ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo xlt("EMR Code"); ?></th>
                            <th><?php echo xlt("EMR Name"); ?></th>
                            <th><?php echo xlt("ELIS ID"); ?></th>
                            <th><?php echo xlt("ELIS Name"); ?></th>
                            <th><?php echo xlt("LOINC"); ?></th>
                            <th><?php echo xlt("SNOMED"); ?></th>
                            <th><?php echo xlt("Units"); ?></th>
                            <th><?php echo xlt("Status"); ?></th>
                            <th class="text-end"><?php echo xlt("Actions"); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mapped as $row): ?>
                        <tr id="mapped-<?php echo attr($row['mapping_id']); ?>">
                            <td><code><?php echo text($row['procedure_code']); ?></code></td>
                            <td><?php echo text($row['name']); ?></td>
                            <td><code><?php echo text($row['openelis_test_id']); ?></code></td>
                            <td><?php echo text($row['openelis_test_name'] ?? ''); ?></td>
                            <td>
                                <?php if (!empty($row['loinc_code'])): ?>
                                    <span class="badge bg-info text-dark code-badge"><?php echo text($row['loinc_code']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['snomed_specimen'])): ?>
                                    <span class="badge bg-warning text-dark code-badge"><?php echo text($row['snomed_specimen']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo text($row['units'] ?? '') ?: '—'; ?></td>
                            <td>
                                <?php if ($row['is_active']): ?>
                                    <span class="badge badge-active"><?php echo xlt("Active"); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-inactive"><?php echo xlt("Inactive"); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="toggleRow('mapped-<?php echo attr($row['mapping_id']); ?>-form')">
                                    <?php echo xlt("Edit"); ?>
                                </button>
                                <form method="post" class="d-inline"
                                      onsubmit="return confirm('<?php echo xla("Are you sure you want to change the status?"); ?>');">
                                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="mapping_id" value="<?php echo attr($row['mapping_id']); ?>">
                                    <?php if ($row['is_active']): ?>
                                        <button type="submit" class="btn btn-sm btn-outline-warning"><?php echo xlt("Deactivate"); ?></button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-outline-success"><?php echo xlt("Activate"); ?></button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <tr class="form-inline-row" id="mapped-<?php echo attr($row['mapping_id']); ?>-form">
                            <td colspan="9">
                                <form method="post" class="p-2 border rounded bg-light">
                                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="action" value="save_mapping">
                                    <input type="hidden" name="procedure_code" value="<?php echo attr($row['procedure_code']); ?>">
                                    <input type="hidden" name="procedure_name" value="<?php echo attr($row['name']); ?>">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("OpenELIS Test ID"); ?></label>
                                            <input type="text" class="form-control form-control-sm" name="openelis_test_id" required
                                                   value="<?php echo attr($row['openelis_test_id']); ?>">
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("OpenELIS Test Name"); ?></label>
                                            <input type="text" class="form-control form-control-sm" name="openelis_test_name"
                                                   value="<?php echo attr($row['openelis_test_name'] ?? ''); ?>">
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("LOINC Code"); ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="loinc_code" id="loinc-m-<?php echo attr($row['mapping_id']); ?>"
                                                       value="<?php echo attr($row['loinc_code'] ?? ''); ?>"
                                                       placeholder="<?php echo attr("e.g., 2345-7"); ?>">
                                                <button type="button" class="btn btn-outline-secondary btn-code-finder"
                                                        onclick="openCodeFinder('LOINC', 'loinc-m-<?php echo attr($row['mapping_id']); ?>')"
                                                        title="<?php echo attr("Search LOINC codes"); ?>">🔍</button>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("SNOMED Specimen"); ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="snomed_specimen" id="snomed-sp-m-<?php echo attr($row['mapping_id']); ?>"
                                                       value="<?php echo attr($row['snomed_specimen'] ?? ''); ?>"
                                                       placeholder="<?php echo attr("e.g., 119297000"); ?>">
                                                <button type="button" class="btn btn-outline-secondary btn-code-finder"
                                                        onclick="openCodeFinder('SNOMED-CT', 'snomed-sp-m-<?php echo attr($row['mapping_id']); ?>')"
                                                        title="<?php echo attr("Search SNOMED codes"); ?>">🔍</button>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("SNOMED Finding"); ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="snomed_finding" id="snomed-fn-m-<?php echo attr($row['mapping_id']); ?>"
                                                       value="<?php echo attr($row['snomed_finding'] ?? ''); ?>"
                                                       placeholder="<?php echo attr("e.g., 33747003"); ?>">
                                                <button type="button" class="btn btn-outline-secondary btn-code-finder"
                                                        onclick="openCodeFinder('SNOMED-CT', 'snomed-fn-m-<?php echo attr($row['mapping_id']); ?>')"
                                                        title="<?php echo attr("Search SNOMED codes"); ?>">🔍</button>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("Units"); ?></label>
                                            <input type="text" class="form-control form-control-sm" name="units"
                                                   value="<?php echo attr($row['units'] ?? ''); ?>"
                                                   placeholder="<?php echo attr("e.g., mg/dL"); ?>" style="width:80px;">
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-sm btn-success"><?php echo xlt("Save"); ?></button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    onclick="toggleRow('mapped-<?php echo attr($row['mapping_id']); ?>-form')">
                                                <?php echo xlt("Cancel"); ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php renderPagination('page_mapped', $page_mapped, $totalPagesMapped, $search); ?>
        <?php endif; ?>
    </div>

<script>
function toggleRow(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.toggle('open');
    }
}

// ── Native OpenEMR Code Finder integration ──────────────────────────────
var _oeCodeTarget = null;

function openCodeFinder(codeType, targetInputId) {
    _oeCodeTarget = targetInputId;
    var url = '<?php echo $webRoot; ?>/interface/patient_file/encounter/find_code_popup.php'
            + '?codetype=' + encodeURIComponent(codeType);
    if (typeof dlgopen === 'function') {
        dlgopen(url, '_blank', 800, 600);
    } else {
        window.top.restoreSession();
        window.open(url, '_blank', 'width=800,height=600,resizable=yes,scrollbars=yes');
    }
}

window.set_related = function(codetype, code, form_name, codedesc) {
    if (_oeCodeTarget && code) {
        var el = document.getElementById(_oeCodeTarget);
        if (el) {
            el.value = code;
        }
        _oeCodeTarget = null;
    }
};
</script>
</body>
</html>
<?php

// ── Helper functions ────────────────────────────────────────────────────

function renderPagination(string $paramName, int $currentPage, int $totalPages, string $search): void
{
    if ($totalPages <= 1) {
        return;
    }

    echo '<nav><ul class="pagination pagination-sm justify-content-center">';

    // Previous
    if ($currentPage > 1) {
        $prevParams =([$paramName => $currentPage - 1]);
        if ($search !== '') {
            $prevParams['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($prevParams) . '">'
            . xlt("Previous") . '</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">' . xlt("Previous") . '</span></li>';
    }

    // Page numbers (max 5 visible)
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $p =([$paramName => 1]);
        if ($search !== '') {
            $p['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($p) . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $p =([$paramName => $i]);
        if ($search !== '') {
            $p['search'] = $search;
        }
        if ($i === $currentPage) {
            echo '<li class="page-item active"><span class="page-link">' . text($i) . '</span></li>';
        } else {
            echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($p) . '">' . text($i) . '</a></li>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $p =([$paramName => $totalPages]);
        if ($search !== '') {
            $p['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($p) . '">' . text($totalPages) . '</a></li>';
    }

    // Next
    if ($currentPage < $totalPages) {
        $nextParams =([$paramName => $currentPage + 1]);
        if ($search !== '') {
            $nextParams['search'] = $search;
        }
        echo '<li class="page-item"><a class="page-link" href="admin_mapping.php?' . http_build_query($nextParams) . '">'
            . xlt("Next") . '</a></li>';
    } else {
        echo '<li class="page-item disabled"><span class="page-link">' . xlt("Next") . '</span></li>';
    }

    echo '</ul></nav>';
}
