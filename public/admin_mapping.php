<?php

/**
 * Admin page for mapping OpenEMR procedure codes to OpenELIS test IDs.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Resolve the OpenEMR root by walking up until globals.php is found.
// Works both in the module (dev) and when copied to <root>/public/modules/<name>/ (prod).
// Peers with the same resolver in pending_orders.php and send_order_action.php.
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

use OpenEMR\Modules\OpenElis\Catalog\OpenElisCatalog;

// Load the local OpenELIS test-catalog mirror for autosuggestion. The mirror
// (mod_openelis_test_catalog) is populated by the catalog import
// (catalog_import.php / CatalogImportService). If it is empty (not yet
// imported), show a hint linking to the import page.
$catalogRows = [];
$catalogCount = 0;
try {
    $catalog = new OpenElisCatalog();
    $catalogRows = $catalog->searchTests(null, 2000);
    $catalogCount = count($catalogRows);
} catch (\Exception $e) {
    // Mirror table may not exist yet; the page still works minus autosuggest.
    error_log("OpenELIS catalog autosuggest unavailable: " . $e->getMessage());
}

// JSON payload for the picker: every mirror test (id + display name + sample
// type name) plus the specimen-name -> SNOMED code map, so selecting a test
// can auto-fill its SNOMED specimen code with zero extra round-trips.
$catalogJs = [];
foreach ($catalogRows as $cat) {
    $catalogJs[] = [
        'i' => (string)$cat['openelis_test_id'],
        'n' => ($cat['name_es'] ?? '') !== '' ? (string)$cat['name_es'] : (string)($cat['name_en'] ?? ''),
        's' => (string)($cat['sample_type'] ?? ''),
    ];
}
$specimenMapJs = [];
$rsSpec = sqlStatement("SELECT sample_type, snomed_code FROM mod_openelis_specimen_map WHERE snomed_code IS NOT NULL AND snomed_code <> ''");
while ($rowSpec = sqlFetchArray($rsSpec)) {
    $specimenMapJs[strtolower(trim((string)$rowSpec['sample_type']))] = (string)$rowSpec['snomed_code'];
}

/**
 * Best-effort LOINC code pulled out of a procedure_type.standard_code value
 * (e.g. "LOINC:2345-7") to prefill the mapping form, or '' when absent.
 */
function oe_loinc_from_standard(?string $standardCode): string
{
    if ($standardCode === null || $standardCode === '') {
        return '';
    }
    if (preg_match('/(?:LOINC)\s*:\s*([0-9][0-9.\-]*)/i', $standardCode, $m)) {
        return $m[1];
    }
    return '';
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

// Exclude imaging from the mapping lists: only lab procedures (ordre)
// get mapped, never imaging studies. The type-name column differs across
// OpenEMR builds (procedure_type_name in stock 8.2.0, order_type_name in
// some forks) so we detect it at runtime via INFORMATION_SCHEMA.
$imagingFilter = '';
$rsCols = sqlStatement(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procedure_type'
       AND COLUMN_NAME IN ('order_type_name', 'procedure_type_name')"
);
while ($rowCol = sqlFetchArray($rsCols)) {
    $imagingFilter = " AND (pt." . $rowCol['COLUMN_NAME'] . " IS NULL
        OR pt." . $rowCol['COLUMN_NAME'] . " = ''
        OR pt." . $rowCol['COLUMN_NAME'] . " NOT LIKE '%imaging%')";
    break;
}

// ── Pagination counts ───────────────────────────────────────────────────

$countBase = "FROM procedure_type pt
    LEFT JOIN mod_openelis_code_mapping m ON pt.procedure_code = m.openemr_procedure_code
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord'" . $imagingFilter . $whereExtra;

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
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord' AND m.id IS NULL" . $imagingFilter . $whereExtra . "
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
    WHERE pt.activity = 1 AND pt.procedure_type = 'ord'" . $imagingFilter . $whereExtra . "
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
        .oe-picker-wrap { position: relative; }
        .oe-picker-drop {
            display: none; position: absolute; z-index: 1060; top: 100%; left: 0;
            min-width: 320px; max-width: 340px; max-height: 220px; overflow-y: auto;
            margin: 0; padding: 0.25rem 0; list-style: none; background: #fff;
            border: 1px solid #ced4da; border-radius: 0.25rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .oe-picker-drop.show { display: block; }
        .oe-picker-item {
            padding: 0.35rem 0.75rem; cursor: pointer; display: block;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .oe-picker-item:hover { background: #e9ecef; }
        .oe-picker-item .oe-sm { color: #6c757d; font-size: 0.85em; }
        .oe-picker-caption { min-height: 1.25em; font-size: 0.8rem; }
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
                                        <div class="col-md-3 col-xl-2">
                                            <label class="form-label small"><?php echo xlt("OpenELIS Test"); ?></label>
                                            <div class="oe-picker-wrap">
                                                <input type="text" class="form-control form-control-sm oe-picker-input"
                                                       autocomplete="off" required
                                                       placeholder="<?php echo attr("Search name or id..."); ?>">
                                                <ul class="oe-picker-drop"></ul>
                                            </div>
                                            <input type="hidden" class="oe-picker-id" name="openelis_test_id" value="">
                                            <input type="hidden" class="oe-picker-name" name="openelis_test_name" value="">
                                            <div class="oe-picker-caption text-muted"></div>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small"><?php echo xlt("LOINC Code"); ?></label>
                                            <div class="input-group input-group-sm">
                                                <input type="text" class="form-control" name="loinc_code" id="loinc-<?php echo attr($row['procedure_code']); ?>"
                                                       value="<?php echo attr(oe_loinc_from_standard($row['standard_code'] ?? null)); ?>"
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
                            <th><?php echo xlt("Standard"); ?></th>
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
                            <td class="standard-code">
                                <?php echo ($row['standard_code'] ?? '') !== '' ? text($row['standard_code']) : '—'; ?>
                            </td>
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
                            <td colspan="10">
                                <form method="post" class="p-2 border rounded bg-light">
                                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                                    <input type="hidden" name="action" value="save_mapping">
                                    <input type="hidden" name="procedure_code" value="<?php echo attr($row['procedure_code']); ?>">
                                    <input type="hidden" name="procedure_name" value="<?php echo attr($row['name']); ?>">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3 col-xl-2">
                                            <label class="form-label small"><?php echo xlt("OpenELIS Test"); ?></label>
                                            <div class="oe-picker-wrap">
                                                <input type="text" class="form-control form-control-sm oe-picker-input"
                                                       autocomplete="off" required
                                                       value="<?php echo attr(trim(($row['openelis_test_id'] ?? '') . ' — ' . ($row['openelis_test_name'] ?? ''), ' —')); ?>">
                                                <ul class="oe-picker-drop"></ul>
                                            </div>
                                            <input type="hidden" class="oe-picker-id" name="openelis_test_id" value="<?php echo attr($row['openelis_test_id']); ?>">
                                            <input type="hidden" class="oe-picker-name" name="openelis_test_name" value="<?php echo attr($row['openelis_test_name'] ?? ''); ?>">
                                            <div class="oe-picker-caption text-muted"></div>
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

    <!-- ── Catalog empty warning ─────────────────────────────────────── -->
    <?php if ($catalogCount === 0): ?>
        <div class="alert alert-warning">
            <?php echo xlt("The OpenELIS test catalog is empty. Import it first via"); ?>
            <a href="<?php echo attr($webRoot . '/public/modules/openelis/catalog_import.php'); ?>"><?php echo xlt("Import Catalog"); ?></a>.
        </div>
    <?php endif; ?>

<script>
// The local mirror + the once-only specimen-name -> SNOMED code map, embedded
// so the picker needs no extra requests. OE_SPECIMEN keys are lowercased.
var OE_CATALOG = <?php echo json_encode($catalogJs); ?>;
var OE_SPECIMEN = <?php echo json_encode($specimenMapJs); ?>;
var OE_CATALOG_BY_ID = {};
OE_CATALOG.forEach(function (c) { OE_CATALOG_BY_ID[c.i] = c; });

function toggleRow(id) {
    var el = document.getElementById(id);
    if (el) {
        el.classList.toggle('open');
    }
}

// ── OpenELIS test picker ────────────────────────────────────────────────────
function oeFilterCat(q) {
    q = (q || '').trim().toLowerCase();
    if (q === '') {
        return OE_CATALOG.slice(0, 30);
    }
    var out = [];
    var nQuery = q.replace(/[^0-9]/g, '');
    for (var i = 0; i < OE_CATALOG.length; i++) {
        var c = OE_CATALOG[i];
        var idMatch = c.i.indexOf(q) === 0 || (nQuery !== '' && c.i.indexOf(nQuery) === 0);
        var nameMatch = c.n.toLowerCase().indexOf(q) !== -1;
        if (idMatch || nameMatch) {
            out.push(c);
            if (out.length >= 30) {
                break;
            }
        }
    }
    return out;
}

function oeSpecimenSnomed(sampleType) {
    if (!sampleType) {
        return '';
    }
    return OE_SPECIMEN[sampleType.trim().toLowerCase()] || '';
}

function oeRenderDrop(input, matches) {
    var wrap = input.closest('.oe-picker-wrap');
    if (!wrap) {
        return;
    }
    var drop = wrap.querySelector('.oe-picker-drop');
    drop.innerHTML = '';
    if (matches.length === 0) {
        drop.classList.remove('show');
        return;
    }
    matches.forEach(function (c) {
        var li = document.createElement('li');
        li.className = 'oe-picker-item';
        li.textContent = c.i + ' — ' + c.n;
        if (c.s) {
            var sm = document.createElement('span');
            sm.className = 'oe-sm';
            sm.textContent = ' · ' + c.s;
            li.appendChild(sm);
        }
        li._oeItem = c;
        li.addEventListener('mousedown', function (ev) {
            ev.preventDefault();
            oePick(input, c);
        });
        drop.appendChild(li);
    });
    drop.classList.add('show');
}

function oeCloseAllDrops() {
    var drops = document.querySelectorAll('.oe-picker-drop.show');
    drops.forEach(function (d) { d.classList.remove('show'); });
}

function oePick(input, c) {
    var wrap = input.closest('.oe-picker-wrap');
    var row = input.closest('form');
    input.value = c.i + ' — ' + c.n;
    wrap.querySelector('.oe-picker-id').value = c.i;
    wrap.querySelector('.oe-picker-name').value = c.n;
    var cap = wrap.querySelector('.oe-picker-caption');
    cap.innerHTML = (c.s ? (c.s + ' · ') : '') + c.n;
    // Auto-fill the SNOMED specimen from the picker's sample type when empty.
    var sn = row ? row.querySelector('[name="snomed_specimen"]') : null;
    if (sn && sn.value.trim() === '') {
        var code = oeSpecimenSnomed(c.s);
        if (code) {
            sn.value = code;
        }
    }
    oeCloseAllDrops();
}

document.addEventListener('input', function (e) {
    var input = e.target.closest('.oe-picker-input');
    if (!input) {
        return;
    }
    oeRenderDrop(input, oeFilterCat(input.value));
    // A directly-typed, exact catalog id still maps itself (no pick needed).
    var viaId = OE_CATALOG_BY_ID[input.value.trim()];
    if (viaId) {
        var wrap = input.closest('.oe-picker-wrap');
        wrap.querySelector('.oe-picker-id').value = viaId.i;
        wrap.querySelector('.oe-picker-name').value = viaId.n;
    }
});

document.addEventListener('keydown', function (e) {
    var wrap = e.target.closest('.oe-picker-wrap');
    if (!wrap) {
        return;
    }
    var drop = wrap.querySelector('.oe-picker-drop');
    if (!drop || !drop.classList.contains('show')) {
        return;
    }
    if (e.key === 'Enter' && drop.children.length > 0 && drop.children[0]._oeItem) {
        e.preventDefault();
        oePick(e.target, drop.children[0]._oeItem);
    } else if (e.key === 'Escape') {
        oeCloseAllDrops();
    }
});

document.addEventListener('click', function (e) {
    if (!e.target.closest('.oe-picker-wrap')) {
        oeCloseAllDrops();
    }
});

document.addEventListener('submit', function (e) {
    var wrap = e.target.querySelector('.oe-picker-wrap');
    if (!wrap) {
        return;
    }
    var idH = wrap.querySelector('.oe-picker-id');
    var text = wrap.querySelector('.oe-picker-input').value.trim();
    if (!idH.value.trim() && text !== '') {
        // No catalog match picked — save the raw typed value as the test id.
        idH.value = text;
    }
});

// Prefill captions for already-mapped rows.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.oe-picker-wrap').forEach(function (wrap) {
        var idH = wrap.querySelector('.oe-picker-id');
        if (!idH || !idH.value) {
            return;
        }
        var c = OE_CATALOG_BY_ID[idH.value] || null;
        var cap = wrap.querySelector('.oe-picker-caption');
        if (!cap) {
            return;
        }
        if (c) {
            cap.innerHTML = (c.s ? (c.s + ' · ') : '') + c.n;
        } else {
            var nameH = wrap.querySelector('.oe-picker-name');
            if (nameH && nameH.value) {
                cap.innerHTML = nameH.value;
            }
        }
    });
});

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
