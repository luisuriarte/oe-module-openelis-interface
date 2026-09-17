<?php

/**
 * Admin page for bulk-importing the OpenELIS catalog (active tests, grouped
 * by OpenELIS test section) into OpenEMR's procedure catalog, per lab provider.
 *
 * Reads the OpenELIS REST test-catalog API using the provider's catalog ADMIN
 * credentials (mod_openelis_catalog_login / password), configured in the native
 * Procedure Providers edit form. Supports a dry-run preview first and a
 * separate confirm action, both via AJAX.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Resolve the OpenEMR root by walking up until globals.php is found.
// Works both in the module (dev) and when copied to <root>/public/modules/<name>/ (prod).
// Peers with the same resolver in admin_mapping.php, pending_orders.php, etc.
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

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\OpenElis\Service\CatalogImportService;

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

// ── AJAX actions: preview (dry-run) and import (apply) ───────────────────
// They answer JSON; everything else renders the page.
$action = $_POST['action'] ?? '';
$isAjax = $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($action, ['preview', 'import'], true)
    && (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch');

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (!oe_module_csrf_verify($_POST['csrf_token_form'] ?? '', 'OpenElisModule')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => xl('Invalid CSRF token')]);
        exit;
    }

    $providerId = (int)($_POST['provider_id'] ?? 0);
    if ($providerId <= 0 || !CatalogImportService::providerExists($providerId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => xl('Select a valid lab provider.')]);
        exit;
    }

    try {
        $service = new CatalogImportService();
        $summary = $action === 'preview'
            ? $service->importCatalogForProvider($providerId, true)
            : $service->importCatalogForProvider($providerId, false);
        echo json_encode(['success' => true, 'summary' => $summary]);
    } catch (\Throwable $e) {
        error_log("OpenELIS catalog import error (provider #$providerId, $action): " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Page data ───────────────────────────────────────────────────────────
// Catalog credentials (mod_openelis_catalog_login/password) are managed on the
// native Procedure Providers edit form (see patches/procedure_provider_edit.php).
// A provider is an "OpenELIS lab" (and importable here) only when it has the
// catalog ADMIN login set — the same marker pending_orders / probe_results_api
// use, so PACS or other non-lab providers never appear in this page.
$providers = [];
$rsProviders = sqlStatement(
    "SELECT pp.ppid, pp.name, pp.protocol, pp.remote_host, pp.mod_openelis_catalog_login,
            (SELECT MAX(m.imported_at) FROM mod_openelis_code_mapping m
              WHERE m.provider_id = pp.ppid AND m.import_source = 'catalog_import') AS last_import
     FROM procedure_providers pp
     WHERE pp.active = 1
       AND pp.mod_openelis_catalog_login IS NOT NULL AND pp.mod_openelis_catalog_login != ''
     ORDER BY pp.name"
);
while ($row = sqlFetchArray($rsProviders)) {
    // A provider is "catalog-ready" only when it can call its own OpenELIS
    // test-catalog API (WS protocol + host + the ADMIN catalog login).
    $row['catalog_ready'] = ($row['protocol'] ?? '') === 'WS'
        && !empty($row['remote_host'])
        && !empty($row['mod_openelis_catalog_login']);
    $providers[] = $row;
}

$selectedProvider = (int)($_POST['provider_id'] ?? ($_GET['provider_id'] ?? 0));
if (!in_array($selectedProvider, array_map('intval', array_column($providers, 'ppid')), true)) {
    $selectedProvider = isset($providers[0]['ppid']) ? (int)$providers[0]['ppid'] : 0;
}

$csrfToken = oe_module_csrf_collect('OpenElisModule');
$webRoot = $GLOBALS['webroot'] ?? '';
$scriptsUrl = $webRoot . '/public/modules/openelis/';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("Import Catalog"); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .cfg-hint { font-size: 0.82rem; color: #6c757d; }
        .count-card { border-left: 4px solid #0d6efd; }
        .count-card .num { font-size: 1.6rem; font-weight: 600; }
        .import-list { max-height: 260px; overflow-y: auto; font-size: 0.85rem; }
        .import-list li { margin-bottom: 0.25rem; }
        .provider-card { border-left: 4px solid #adb5bd; }
        .provider-card.provider-ready { border-left-color: #198754; }
        #result { display: none; }
    </style>
</head>
<body class="container-fluid">
<div class="card mt-3">
    <div class="card-header">
        <h4><?php echo xlt("Import Catalog"); ?></h4>
        <div class="cfg-hint">
            <?php echo xlt("Imports the OpenELIS test catalog (active tests, grouped by OpenELIS test section) into the OpenEMR lab procedure catalog, respecting each provider's own catalog. Manual code mappings are never overwritten."); ?>
        </div>
    </div>
    <div class="card-body">
        <!-- Per-provider actions: each lab has its own OpenELIS to sync. -->
        <div class="row g-2 mb-3" id="providers_list">
            <?php foreach ($providers as $p): ?>
                <div class="col-md-6">
                    <div class="card provider-card h-100<?php echo $p['catalog_ready'] ? ' provider-ready' : ''; ?>">
                        <div class="card-body py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                            <div>
                                <span class="fw-semibold"><?php echo text($p['name']); ?></span>
                                <?php if (!empty($p['protocol'])): ?>
                                    <span class="cfg-hint"> (<?php echo text($p['protocol']); ?>)</span>
                                <?php endif; ?>
                                <?php if ($p['catalog_ready']): ?>
                                    <span class="badge text-bg-success ms-1"><?php echo xlt("Catalog ready"); ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary ms-1"><?php echo xlt("Incomplete (WS protocol / host)"); ?></span>
                                <?php endif; ?>
                                <div class="cfg-hint">
                                    <?php echo xlt("Last import"); ?>:
                                    <?php echo !empty($p['last_import']) ? text($p['last_import']) : xlt("Never"); ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" id="btn_preview_<?php echo attr($p['ppid']); ?>"
                                        class="btn btn-sm btn-outline-primary provider-action"
                                        data-action="preview" data-provider="<?php echo attr($p['ppid']); ?>"<?php echo $p['catalog_ready'] ? '' : ' disabled'; ?>>
                                    <?php echo xlt("Preview"); ?>
                                </button>
                                <button type="button" id="btn_import_<?php echo attr($p['ppid']); ?>"
                                        class="btn btn-sm btn-success provider-action"
                                        data-action="import" data-provider="<?php echo attr($p['ppid']); ?>"<?php echo $p['catalog_ready'] ? '' : ' disabled'; ?>>
                                    <?php echo xlt("Update tests"); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Provider select -->
        <div class="row g-2 align-items-end mb-3">
            <div class="col-md-6">
                <label class="form-label"><?php echo xlt("Lab provider"); ?></label>
                <select id="provider_id" class="form-select">
                    <option value=""><?php echo xlt("Select a lab provider..."); ?></option>
                    <?php foreach ($providers as $p): ?>
                        <option value="<?php echo attr($p['ppid']); ?>"<?php echo (int)$p['ppid'] === $selectedProvider ? ' selected' : ''; ?>>
                            <?php echo text($p['name'] . ($p['protocol'] ? ' (' . $p['protocol'] . ')' : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2">
                    <button type="button" id="btn_preview" class="btn btn-primary">
                        <?php echo xlt("Preview"); ?>
                    </button>
                    <button type="button" id="btn_import" class="btn btn-success" disabled>
                        <?php echo xlt("Confirm import"); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php if (empty($providers)): ?>
            <div class="alert alert-warning">
                <?php echo xlt("No OpenELIS lab providers found. On the native Procedure Providers edit form, open your lab provider (the one named after your OpenELIS installation, e.g. OpenELIS) and set its OpenELIS Catalog Login / Password. A provider only appears here once those catalog credentials are saved."); ?>
            </div>
        <?php endif; ?>

        <!-- Result -->
        <div id="result"></div>

        <div class="d-flex gap-2">
            <a href="<?php echo attr($scriptsUrl . 'admin_mapping.php'); ?>" class="btn btn-secondary">
                <?php echo xlt("Go to Code Mapping"); ?>
            </a>
        </div>
    </div>
</div>

<input type="hidden" id="csrf_token" value="<?php echo attr($csrfToken); ?>">
<?php
// UI strings: English (US) sources via xl() so the hospital language applies in
// the browser too. Placeholder tokens ({code}, {provider}, {n}, {name}) are
// substituted on the client, never inside the stored constant.
$tr = [
    'manualMapSublabel'      => xl('Manual mapping: {code} — auto import skipped (manual mapping prevails).'),
    'dryRunAlert'            => xl('DRY RUN — nothing was written to the database. Review the summary, then confirm the import.'),
    'importDone'             => xl('Import completed for {provider}.'),
    'sectionsGroups'         => xl('Sections (groups)'),
    'testsImported'          => xl('Tests imported'),
    'activeInCatalog'        => xl('Active tests in catalog'),
    'groupsCreated'          => xl('Groups created'),
    'groupsUpdated'          => xl('Groups updated'),
    'testsCreated'           => xl('Tests created'),
    'testsUpdated'           => xl('Tests updated'),
    'mappingsCreated'        => xl('Mappings created'),
    'mappingsUpdated'        => xl('Mappings updated'),
    'excludedError'          => xl('Excluded (error)'),
    'withWarnings'           => xl('With warnings'),
    'inactiveNotFound'       => xl('Inactive / not found'),
    'conflictsManual'        => xl('Conflicts with manual mappings'),
    'samplesNoSnomed'        => xl('Sample types without SNOMED'),
    'sectionsDeactivated'    => xl('Sections deactivated'),
    'testsDeactivated'       => xl('Tests deactivated'),
    'sectionsReactivated'    => xl('Sections reactivated'),
    'testsReactivated'       => xl('Tests reactivated'),
    'excludedErrorTitle'     => xl('Excluded by error'),
    'withWarningsTitle'      => xl('With catalog warnings'),
    'samplesNoSnomedTitle'   => xl('Sample types without SNOMED code'),
    'sectionsDeactivatedTitle' => xl('Sections deactivated (absent from catalog)'),
    'testsDeactivatedTitle'    => xl('Tests deactivated (absent from catalog)'),
    'sectionsReactivatedTitle' => xl('Sections reactivated (back in catalog)'),
    'testsReactivatedTitle'    => xl('Tests reactivated (back in catalog)'),
    'noExcluded'             => xl('No tests excluded by error.'),
    'noWarnings'             => xl('No tests with warnings.'),
    'noInactive'             => xl('No inactive tests.'),
    'noConflicts'            => xl('No conflicts with manual mappings.'),
    'allSnomed'              => xl('All sample types have a SNOMED code.'),
    'noSectionsDeactivated'  => xl('No sections deactivated.'),
    'noTestsDeactivated'     => xl('No tests deactivated.'),
    'noSectionsReactivated'  => xl('No sections reactivated.'),
    'noTestsReactivated'     => xl('No tests reactivated.'),
    'countTests'             => xl('{n} test'),
    'countTestsPlural'       => xl('{n} tests'),
    'exampleSnomed'          => xl('Example: {name}. Fill in the SNOMED code in mod_openelis_specimen_map and re-import.'),
    'errPrefix'              => xl('Error:'),
    'selectProvider'         => xl('Select a lab provider.'),
    'waiting'                => xl('Waiting...'),
    'unknownError'           => xl('Unknown error'),
];
?>
<script>
    const TR = <?php echo json_encode($tr, JSON_UNESCAPED_UNICODE); ?>;
    const CSRF_TOKEN = document.getElementById('csrf_token').value;
    const providerSelect = document.getElementById('provider_id');
    const btnPreview = document.getElementById('btn_preview');
    const btnImport = document.getElementById('btn_import');
    const resultBox = document.getElementById('result');

    function buildList(items, emptyText) {
        const ul = document.createElement('ul');
        ul.className = 'list-group list-group-flush import-list';
        if (!items || Object.keys(items).length === 0) {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = emptyText;
            ul.appendChild(li);
            return ul;
        }
        for (const [id, raw] of Object.entries(items)) {
            const info = (raw && typeof raw === 'object') ? raw : { name: String(raw == null ? id : raw) };
            const li = document.createElement('li');
            li.className = 'list-group-item';
            const cab = document.createElement('span');
            cab.style.fontWeight = '600';
            cab.textContent = (info.name || info.procedure_name || id) + ' (' + id + ')';
            li.appendChild(cab);
            for (const msg of (info.messages || [])) {
                const sub = document.createElement('div');
                sub.className = 'text-muted';
                sub.textContent = msg;
                li.appendChild(sub);
            }
            if (info.procedure_code) {
                const sub = document.createElement('div');
                sub.className = 'text-muted';
                sub.textContent = TR.manualMapSublabel.replace('{code}', info.procedure_code);
                li.appendChild(sub);
            }
            ul.appendChild(li);
        }
        return ul;
    }

    function renderResult(s) {
        const box = resultBox;
        box.style.display = 'block';
        box.innerHTML = '';

        const alert = document.createElement('div');
        alert.className = s.dry_run ? 'alert alert-info' : 'alert alert-success';
        alert.textContent = s.dry_run
            ? TR.dryRunAlert
            : TR.importDone.replace('{provider}', s.provider_name);
        box.appendChild(alert);

        const row = document.createElement('div');
        row.className = 'row g-2 mb-3';
        const cards = [
            [TR.sectionsGroups, s.panels],
            [TR.testsImported, s.tests_imported],
            [TR.activeInCatalog, s.catalog_total != null ? s.catalog_total : '—'],
            [TR.groupsCreated, s.groups_created],
            [TR.groupsUpdated, s.groups_updated],
            [TR.testsCreated, s.tests_created],
            [TR.testsUpdated, s.tests_updated],
            [TR.mappingsCreated, s.mappings_inserted],
            [TR.mappingsUpdated, s.mappings_updated],
            [TR.excludedError, Object.keys(s.excluded_by_error || {}).length],
            [TR.withWarnings, Object.keys(s.tests_with_warnings || {}).length],
            [TR.inactiveNotFound, Object.keys(s.inactive_missing || {}).length],
            [TR.conflictsManual, Object.keys(s.conflicts || {}).length],
            [TR.samplesNoSnomed, Object.keys(s.specimen_unmapped || {}).length],
            [TR.sectionsDeactivated, Object.keys(s.deactivated_panels || {}).length],
            [TR.testsDeactivated, Object.keys(s.deactivated_tests || {}).length],
            [TR.sectionsReactivated, Object.keys(s.reactivated_panels || {}).length],
            [TR.testsReactivated, Object.keys(s.reactivated_tests || {}).length],
        ];
        for (const [label, value] of cards) {
            const col = document.createElement('div');
            col.className = 'col-md-2 col-6';
            const c = document.createElement('div');
            c.className = 'card count-card h-100';
            c.innerHTML = '<div class="card-body py-2"></div>';
            const b = c.querySelector('.card-body');
            const n = document.createElement('div');
            n.className = 'num text-primary'; n.textContent = value;
            const l = document.createElement('div');
            l.className = 'cfg-hint'; l.textContent = label;
            b.appendChild(n); b.appendChild(l);
            col.appendChild(c); row.appendChild(col);
        }
        box.appendChild(row);

        const dets = [
            [TR.excludedErrorTitle, s.excluded_by_error || {}, TR.noExcluded],
            [TR.withWarningsTitle, s.tests_with_warnings || {}, TR.noWarnings],
            [TR.inactiveNotFound, s.inactive_missing || {}, TR.noInactive],
            [TR.conflictsManual, s.conflicts || {}, TR.noConflicts],
            [
                TR.samplesNoSnomedTitle,
                Object.fromEntries(Object.entries(s.specimen_unmapped || {}).map(([st, v]) => [
                    st,
                    {
                        name: st + ' (' + (v.tests === 1 ? TR.countTests : TR.countTestsPlural).replace('{n}', v.tests) + ')',
                        messages: v.example
                            ? [TR.exampleSnomed.replace('{name}', v.example)]
                            : [],
                    },
                ])),
                TR.allSnomed,
            ],
            [TR.sectionsDeactivatedTitle, s.deactivated_panels || {}, TR.noSectionsDeactivated],
            [TR.testsDeactivatedTitle, s.deactivated_tests || {}, TR.noTestsDeactivated],
            [TR.sectionsReactivatedTitle, s.reactivated_panels || {}, TR.noSectionsReactivated],
            [TR.testsReactivatedTitle, s.reactivated_tests || {}, TR.noTestsReactivated],
        ];
        for (const [title, data, emptyText] of dets) {
            const card = document.createElement('div');
            card.className = 'card mb-2';
            const h = document.createElement('div');
            h.className = 'card-header py-2';
            h.textContent = title;
            card.appendChild(h);
            if (Object.keys(data).length > 0) {
                const body = document.createElement('div');
                body.className = 'card-body py-2';
                body.appendChild(buildList(data, emptyText));
                card.appendChild(body);
            } else {
                const body = document.createElement('div');
                body.className = 'card-body py-2 text-muted';
                body.textContent = emptyText;
                card.appendChild(body);
            }
            box.appendChild(card);
        }

        if (s.dry_run) {
            btnImport.disabled = false;
        }
    }

    function showError(msg) {
        resultBox.style.display = 'block';
        resultBox.innerHTML = '';
        const alert = document.createElement('div');
        alert.className = 'alert alert-danger';
        alert.textContent = TR.errPrefix + ' ' + msg;
        resultBox.appendChild(alert);
    }

    function run(action, pid, btnEl) {
        const providerId = pid != null ? String(pid) : providerSelect.value;
        if (!providerId) {
            showError(TR.selectProvider);
            return;
        }
        if (pid != null) { providerSelect.value = providerId; }
        const isPreview = action === 'preview';
        const btn = btnEl || (isPreview ? btnPreview : btnImport);
        if (btn) { btn.disabled = true; }
        const orig = btn ? btn.textContent : '';
        if (btn) { btn.textContent = TR.waiting; }

        const fd = new FormData();
        fd.append('csrf_token_form', CSRF_TOKEN);
        fd.append('action', action);
        fd.append('provider_id', providerId);

        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'fetch' },
        })
            .then(r => r.json().catch(() => ({ success: false, message: 'HTTP ' + r.status })))
            .then(res => {
                if (res.success) {
                    renderResult(res.summary);
                    if (action === 'import') { btnImport.disabled = true; }
                } else {
                    showError(res.message || TR.unknownError);
                }
            })
            .catch(err => showError(String(err)))
            .finally(() => { if (btn) { btn.disabled = false; btn.textContent = orig; } });
    }

    btnPreview.addEventListener('click', () => run('preview', null));
    btnImport.addEventListener('click', () => run('import', null));

    // Per-provider buttons: sync a single lab's own OpenELIS catalog.
    document.querySelectorAll('.provider-action').forEach(btn => {
        btn.addEventListener('click', () => run(btn.dataset.action, btn.dataset.provider, btn));
    });
</script>
</body>
</html>