<?php

/**
 * Diagnostic probe for the OpenELIS FHIR results endpoint.
 *
 * Copy this file next to send_order_action.php (public/modules/openelis/)
 * and open:
 *   probe_results_api.php?ppid=4                  -> first 5 DiagnosticReports (raw)
 *   probe_results_api.php?ppid=4&sr=ServiceRequest/<uuid>  -> reports based on that
 *              ServiceRequest, plus the Observations each report references.
 *
 * It prints raw JSON payloads so you can confirm the actual field names the
 * OpenELIS (HAPI FHIR) server returns before wiring the result import.
 *
 * @package OpenEMR
 */

// ---- locate OpenEMR root (same strategy as send_order_action.php) ----
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
    http_response_code(500);
    echo "OpenEMR root not found\n";
    exit;
}
require_once $__found . '/globals.php';
unset($__found, $__oeRoot, $__probeRoot, $__probeIface, $__g, $__guesses);

use OpenEMR\Modules\OpenElis\Client\OpenElisApiClient;

header('Content-Type: text/plain; charset=utf-8');

$ppid = (int)($_GET['ppid'] ?? 0);
if ($ppid > 0) {
    $provider = sqlQuery(
        "SELECT ppid, name, remote_host, login, protocol, active,
                mod_openelis_catalog_login
         FROM procedure_providers WHERE ppid = ?",
        [$ppid]
    );
} else {
    $provider = sqlQuery(
        "SELECT ppid, name, remote_host, login, protocol, active,
                mod_openelis_catalog_login
         FROM procedure_providers
         WHERE protocol = 'WS' AND active = 1
           AND mod_openelis_catalog_login IS NOT NULL AND mod_openelis_catalog_login != ''
         ORDER BY ppid LIMIT 1"
    );
}

if (empty($provider) || empty($provider['remote_host'])) {
    echo "No lab provider found. Use ?ppid=<procedure_providers.ppid>\n";
    exit;
}

$client = new OpenElisApiClient($provider['remote_host'], $provider['login'], $provider['password']);
echo "Provider: #{$provider['ppid']} {$provider['name']} ({$provider['remote_host']}) protocol={$provider['protocol']}\n\n";

$sr = (string)($_GET['sr'] ?? '');

if ($sr !== '') {
    echo "== DiagnosticReport?based-on=$sr ==\n";
    $reports = $client->findDiagnosticReportsByServiceRequest($sr);
    echo "count: " . count($reports) . "\n";
    foreach ($reports as $report) {
        echo "---- report " . ($report['id'] ?? '(no id)') . " ----\n";
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        echo "-- observations (via _include / per-ref fallback) --\n";
        foreach ($client->fetchReportObservations($report) as $obs) {
            echo json_encode($obs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
} else {
    echo "== DiagnosticReport?_count=5&_sort=-issued (raw) ==\n";
    $list = $client->listDiagnosticReports(5);
    echo "count: " . count($list) . "\n";
    foreach ($list as $report) {
        echo "---- report " . ($report['id'] ?? '(no id)') . " status=" . ($report['status'] ?? '?') . " ----\n";
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}