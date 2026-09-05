<?php

/**
 * Admin page for bulk-importing the OpenELIS catalog (panels + ordered tests)
 * into OpenEMR's procedure catalog, per lab provider.
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
$providers = [];
$rsProviders = sqlStatement(
    "SELECT ppid, name, protocol
     FROM procedure_providers WHERE active = 1 ORDER BY name"
);
while ($row = sqlFetchArray($rsProviders)) {
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
        #result { display: none; }
    </style>
</head>
<body class="container-fluid">
<div class="card mt-3">
    <div class="card-header">
        <h4><?php echo xlt("Import Catalog"); ?></h4>
        <div class="cfg-hint">
            <?php echo xlt("Imports the OpenELIS test catalog (panels + ordered tests) into the OpenEMR lab procedure catalog, respecting each provider's own catalog. Tests with catalog errors are excluded; warnings are reported. Manual code mappings are never overwritten."); ?>
        </div>
    </div>
    <div class="card-body">
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
                <?php echo xlt("No active lab providers found. Configure one in the lab providers section first."); ?>
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
<script>
    const CSRF_TOKEN = document.getElementById('csrf_token').value;
    const providerSelect = document.getElementById('provider_id');
    const btnPreview = document.getElementById('btn_preview');
    const btnImport = document.getElementById('btn_import');
    const resultBox = document.getElementById('result');

    function buildList(textos, vacio) {
        const ul = document.createElement('ul');
        ul.className = 'list-group list-group-flush import-list';
        if (!textos || Object.keys(textos).length === 0) {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = vacio;
            ul.appendChild(li);
            return ul;
        }
        for (const [id, info] of Object.entries(textos)) {
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
                sub.textContent = 'Mapeo manual: ' + info.procedure_code + ' — importación automática omitida (el mapeo manual prevalece).';
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
            ? 'SIMULACIÓN — no se escribió nada en la base de datos. Revisá el resumen y confirmá la importación.'
            : 'Importación completada para ' + s.provider_name + '.';
        box.appendChild(alert);

        const row = document.createElement('div');
        row.className = 'row g-2 mb-3';
        const withIssues = s.catalog_totalWithIssues != null
            ? ' | errores: ' + s.catalog_totalErrors + ' advertencias: ' + s.catalog_totalWarnings
            : '';
        const cards = [
            ['Panels', s.panels],
            ['Tests importados', s.tests_imported],
            ['Catálogo activo', s.catalog_total != null ? s.catalog_total + (withIssues || '') : '—'],
            ['Grupos creados', s.groups_created],
            ['Grupos actualizados', s.groups_updated],
            ['Tests creados', s.tests_created],
            ['Tests actualizados', s.tests_updated],
            ['Mapeos creados', s.mappings_inserted],
            ['Mapeos actualizados', s.mappings_updated],
            ['Excluidos (error)', Object.keys(s.excluded_by_error || {}).length],
            ['Con advertencias', Object.keys(s.tests_with_warnings || {}).length],
            ['Inactivos/no encontrados', Object.keys(s.inactive_missing || {}).length],
            ['Conflictos con mapeos manuales', Object.keys(s.conflicts || {}).length],
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
            ['Excluidos por error', s.excluded_by_error || {}, 'Sin tests excluidos por error.'],
            ['Con advertencias de catálogo', s.tests_with_warnings || {}, 'Sin tests con advertencias.'],
            ['Inactivos / no encontrados', s.inactive_missing || {}, 'Sin tests inactivos.'],
            ['Conflictos con mapeos manuales', s.conflicts || {}, 'Sin conflictos con mapeos manuales.'],
        ];
        for (const [titulo, data, vacio] of dets) {
            const card = document.createElement('div');
            card.className = 'card mb-2';
            const h = document.createElement('div');
            h.className = 'card-header py-2';
            h.textContent = titulo;
            card.appendChild(h);
            if (Object.keys(data).length > 0) {
                const body = document.createElement('div');
                body.className = 'card-body py-2';
                body.appendChild(buildList(data, vacio));
                card.appendChild(body);
            } else {
                const body = document.createElement('div');
                body.className = 'card-body py-2 text-muted';
                body.textContent = vacio;
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
        alert.textContent = 'Error: ' + msg;
        resultBox.appendChild(alert);
    }

    function run(action) {
        const pid = providerSelect.value;
        if (!pid) {
            showError('Seleccioná un proveedor de laboratorio.');
            return;
        }
        const btn = action === 'preview' ? btnPreview : btnImport;
        btn.disabled = true;
        const orig = btn.textContent;
        btn.textContent = 'Espere…';

        const fd = new FormData();
        fd.append('csrf_token_form', CSRF_TOKEN);
        fd.append('action', action);
        fd.append('provider_id', pid);

        fetch(window.location.pathname, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'fetch' },
        })
            .then(r => r.json().catch(() => ({ success: false, message: 'HTTP ' + r.status })))
            .then(res => {
                if (res.success) {
                    if (action === 'preview') { renderResult(res.summary); }
                    else { renderResult(res.summary); btnImport.disabled = true; }
                } else {
                    showError(res.message || 'Error desconocido');
                }
            })
            .catch(err => showError(String(err)))
            .finally(() => { btn.disabled = false; btn.textContent = orig; });
    }

    btnPreview.addEventListener('click', () => run('preview'));
    btnImport.addEventListener('click', () => run('import'));
</script>
</body>
</html>