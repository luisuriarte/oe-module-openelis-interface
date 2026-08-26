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

require_once dirname(__FILE__, 5) . '/globals.php';

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
if (!oe_module_csrf_verify($_POST['csrf_token_form'] ?? '', 'OpenElisModule')) {
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
        "SELECT po.lab_id, pp.remote_host, pp.login, pp.password, pp.active
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
