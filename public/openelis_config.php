<?php

/**
 * OpenELIS module settings page.
 *
 * Manages the parameters used by the OpenELIS test-catalog synchronizer
 * (src/Catalog/OpenElisCatalog.php): the numeric id range probed against the
 * REST endpoint /OpenELIS-Global/rest/TestNamesProvider?testId={id}.
 *
 * The API credentials are NOT stored here — the catalog reuses the same lab
 * provider credentials already configured in procedure_providers (login /
 * password / remote_host / protocol = 'WS'), which the send flow (FHIR) also
 * uses. The lab provides only an API user/password, not database credentials.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Resolve the OpenEMR root by walking up until globals.php is found.
// Works both in the module (dev) and when copied to <root>/public/modules/<name>/ (prod).
// Peers with the same resolver in admin_mapping.php, pending_orders.php and send_order_action.php.
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

// ── Load current config ─────────────────────────────────────────────────
$current = [];
$rs = sqlStatement("SELECT cfg_name, cfg_value FROM mod_openelis_config");
while ($row = sqlFetchArray($rs)) {
    $current[$row['cfg_name']] = $row['cfg_value'];
}

$message = '';
$messageClass = '';

// ── Sync catalog immediately ────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'sync_catalog') {
    if (!oe_module_csrf_verify($_POST['csrf_token_form'] ?? '', 'default')) {
        CsrfUtils::csrfNotVerified();
    }

    try {
        $catalog = new OpenElisCatalog();
        $result = $catalog->syncCatalog();
        $message = xl('Catalog synchronized') . ': ' . $result['added'] . ' ' . xl('tests stored');
        if ($result['stopped_by_gap']) {
            $message .= '. ' . xl('Stopped after a run of inactive ids (probed up to') . ' '
                . $result['max_probed'] . ').';
        }
        $messageClass = 'text-success';
    } catch (\Exception $e) {
        $message = xl('Catalog sync failed') . ': ' . $e->getMessage();
        $messageClass = 'text-danger';
    }
}

// ── Save config ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_config') {
    if (!oe_module_csrf_verify($_POST['csrf_token_form'] ?? '', 'default')) {
        CsrfUtils::csrfNotVerified();
    }

    $minId = max(1, (int)($_POST['catalog_min_id'] ?? 1));
    $maxId = max($minId, (int)($_POST['catalog_max_id'] ?? 500));

    // API credentials: preserve the stored password when the field is left blank.
    $apiUser = trim((string)($_POST['api_user'] ?? ''));
    $apiPass = (string)($_POST['api_pass'] ?? '');
    if ($apiPass === '' && !empty($current['api_pass'])) {
        $apiPass = $current['api_pass'];
    }

    sqlStatement("DELETE FROM mod_openelis_config");

    $cfg = [
        'catalog_min_id' => (string)$minId,
        'catalog_max_id' => (string)$maxId,
        'api_user' => $apiUser,
    ];
    if ($apiPass !== '') {
        $cfg['api_pass'] = $apiPass;
    }
    foreach ($cfg as $name => $value) {
        sqlStatement(
            "INSERT INTO mod_openelis_config (cfg_name, cfg_value) VALUES (?, ?)",
            [$name, $value]
        );
    }

    // Reload for display.
    $current = [];
    $rs = sqlStatement("SELECT cfg_name, cfg_value FROM mod_openelis_config");
    while ($row = sqlFetchArray($rs)) {
        $current[$row['cfg_name']] = $row['cfg_value'];
    }

    $message = xl('Configuration saved.');
    $messageClass = 'text-success';
}

// Mirror catalog stats (for display)
$catalogStats = sqlQuery("SELECT COUNT(*) AS total, MAX(updated_at) AS last FROM mod_openelis_test_catalog");
$totalCatalog = (int)($catalogStats['total'] ?? 0);
$lastCatalogSync = $catalogStats['last'] ?? null;

$csrfToken = oe_module_csrf_collect('default');
$webRoot = $GLOBALS['webroot'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("OpenELIS Settings"); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .card-sm { max-width: 560px; }
        .cfg-hint { font-size: 0.82rem; color: #6c757d; }
    </style>
</head>
<body class="container-fluid">
    <div class="card mt-3">
        <div class="card-header">
            <h4><?php echo xlt("OpenELIS Settings"); ?></h4>
            <div class="cfg-hint">
                <?php echo xlt("Catalog of OpenELIS tests, read over the REST API. You can enter a dedicated API user/password here, or leave it blank to reuse the lab provider credentials (procedure_providers, protocol = WS). No database credentials are needed."); ?>
            </div>
        </div>
        <div class="card-body">
            <?php if ($message !== ''): ?>
                <div class="alert <?php echo attr($messageClass) === 'text-danger' ? 'alert-danger' : 'alert-success'; ?>">
                    <?php echo text($message); ?>
                </div>
            <?php endif; ?>

            <!-- Sync button -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5><?php echo xlt("Synchronize test catalog"); ?></h5>
                    <p class="cfg-hint">
                        <?php echo xlt("Local mirror"); ?>: <strong><?php echo text((string)$totalCatalog); ?></strong>
                        <?php echo xlt("tests"); ?>
                        <?php if ($lastCatalogSync): ?>
                            — <?php echo xlt("last sync"); ?> <?php echo text($lastCatalogSync); ?>
                        <?php endif; ?>
                    </p>
                    <form method="post" action="openelis_config.php" class="d-inline">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                        <input type="hidden" name="action" value="sync_catalog">
                        <button type="submit" class="btn btn-primary"><?php echo xlt("Sync now"); ?></button>
                    </form>
                </div>
            </div>

            <!-- API credentials + id range config -->
            <form method="post" action="openelis_config.php" class="card-sm mb-3">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrfToken); ?>">
                <input type="hidden" name="action" value="save_config">

                <h5><?php echo xlt("OpenELIS API credentials"); ?></h5>
                <p class="cfg-hint">
                    <?php echo xlt("User/password for the test-catalog REST endpoint. Leave the user blank to fall back to the lab provider credentials (procedure_providers, protocol = WS)."); ?>
                </p>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label"><?php echo xlt("API user"); ?></label>
                        <input type="text" class="form-control" name="api_user"
                               value="<?php echo attr($current['api_user'] ?? ''); ?>"
                               autocomplete="off" placeholder="<?php echo xlt("e.g. usersync"); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo xlt("API password"); ?></label>
                        <input type="password" class="form-control" name="api_pass"
                               autocomplete="new-password" placeholder="">
                        <div class="cfg-hint">
                            <?php if (!empty($current['api_pass'])): ?>
                                <?php echo xlt("A password is stored. Leave blank to keep it."); ?>
                            <?php else: ?>
                                <?php echo xlt("Optional — falls back to provider credentials."); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <h5><?php echo xlt("Id probing range"); ?></h5>
                <p class="cfg-hint">
                    <?php echo xlt("The endpoint /OpenELIS-Global/rest/TestNamesProvider?testId={id} only accepts one integer id at a time (testId=all fails), so the catalog is built by iterating this range. Increase the max if new tests are added beyond it."); ?>
                </p>

                <div class="row g-2 mb-3">
                    <div class="col">
                        <label class="form-label"><?php echo xlt("From id"); ?></label>
                        <input type="number" min="1" class="form-control" name="catalog_min_id"
                               value="<?php echo attr($current['catalog_min_id'] ?? '1'); ?>">
                    </div>
                    <div class="col">
                        <label class="form-label"><?php echo xlt("To id"); ?></label>
                        <input type="number" min="1" class="form-control" name="catalog_max_id"
                               value="<?php echo attr($current['catalog_max_id'] ?? '500'); ?>">
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><?php echo xlt("Save Configuration"); ?></button>
                    <a href="<?php echo attr($webRoot . '/public/modules/openelis/admin_mapping.php'); ?>" class="btn btn-secondary">
                        <?php echo xlt("Go to Code Mapping"); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
