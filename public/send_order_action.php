<?php

/**
 * AJAX endpoint to send a procedure_order to OpenELIS.
 *
 * Accepts POST with order_id + csrf_token_form.
 * Returns JSON response.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Resolve the OpenEMR root by walking up until globals.php is found.
// Works both in the module (dev) and when copied to <root>/public/ (prod).
//
// This deployment keeps OpenEMR's globals.php under <root>/interface/globals.php
// (the OpenEMR web root is <root>/interface/), while the module's web scripts
// are copied to the sibling <root>/public/modules/<name>/. Because public/ and
// interface/ are siblings, we check BOTH "<dir>/globals.php" and
// "<dir>/interface/globals.php" at each level of the upward walk.
//
// NOTE: file_exists() / is_dir() can return false when PHP-FPM enforces
// open_basedir outside the OpenEMR tree. We also probe with realpath().
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
    // Fallback guesses relative to this script's directory:
    //   <parent>/public/modules/<module>/send_order_action.php  →  <parent>/interface
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
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'OpenEMR root not found']);
    exit;
}
error_log("OpenELIS send_order_action.php located OpenEMR root at " . $__found . " (__DIR__=" . __DIR__ . ")");

require_once $__found . '/globals.php';
unset($__found, $__oeRoot, $__probeRoot, $__probeIface, $__g, $__guesses);

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\OpenElis\Client\OpenElisApiClient;
use OpenEMR\Modules\OpenElis\Service\OrderSyncService;

header('Content-Type: application/json; charset=utf-8');

// CSRF compatibility wrapper (OpenEMR 8.0 vs 8.2+)
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

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ACL check
if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => xl('Access denied')]);
    exit;
}

// CSRF check
if (!oe_module_csrf_verify($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => xl('Invalid CSRF token')]);
    exit;
}

// Validate order_id
$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => xl('Invalid order ID')]);
    exit;
}

// Send to OpenELIS
try {
    // We need a valid OpenElisApiClient to instantiate the service.
    // The service will create its own client from the order's provider.
    // For this endpoint, we need to know the provider info to create the client.
    $order = sqlQuery(
        "SELECT po.lab_id, pp.remote_host, pp.login, pp.password, pp.active, pp.protocol
         FROM procedure_order po
         LEFT JOIN procedure_providers pp ON po.lab_id = pp.ppid
         WHERE po.procedure_order_id = ?",
        [$orderId]
    );

    if (empty($order) || empty($order['remote_host'])) {
        echo json_encode([
            'success' => false,
            'message' => xl('No active lab provider configured for this order'),
        ]);
        exit;
    }

    if (!$order['active']) {
        echo json_encode([
            'success' => false,
            'message' => xl('Lab provider is inactive'),
        ]);
        exit;
    }

    // OpenELIS requires protocol = 'WS' (Web Services). Reject otherwise.
    if (($order['protocol'] ?? '') !== 'WS') {
        echo json_encode([
            'success' => false,
            'message' => xl('Lab provider protocol must be set to Web Services (WS) to send orders via OpenELIS'),
        ]);
        exit;
    }

    $client = new OpenElisApiClient(
        $order['remote_host'],
        $order['login'],
        $order['password']
    );

    $syncService = new OrderSyncService($client);
    $result = $syncService->sendOrderToOpenElis($orderId);

    echo json_encode($result);
} catch (\Exception $e) {
    error_log("OpenELIS sync endpoint error for order #$orderId: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => xl('Internal error') . ': ' . $e->getMessage(),
    ]);
}
