<?php

/**
 * Standalone probe for the OpenELIS Global 2 test-catalog REST API.
 *
 * Not part of the module repo. Runs against the REAL server with the catalog
 * ADMIN credentials and does two things:
 *
 *   1. A raw call to GET /OpenELIS-Global/rest/TestCatalog, dumping the
 *      response's structure (keys present + value types).
 *   2. Explicit comparison against what CatalogApiClient / CatalogImportService
 *      expect to parse (fixed candidate-key lists), flagging DISCREPANCIES.
 *
 * The classic TestCatalog endpoint returns the WHOLE catalog in one document
 * ({ testCatalogList: [...], testSectionList: [...] }): no pagination and no
 * panels list (the /rest/test-catalog/panels* paths do not exist — trying them
 * yields the Tomcat 404 that this probe used to hit).
 *
 * CREDENTIALS (never hardcoded):
 *     env  OPENELIS_PROBE_HOST | OPENELIS_PROBE_LOGIN | OPENELIS_PROBE_PASSWORD
 *   or argv: php probe_catalog_api.php <host> <login> <password>
 *
 * host defaults to https://127.0.0.1:8443
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$MOD = dirname(__DIR__);
require_once $MOD . '/Client/CatalogApiClient.php';

use OpenEMR\Modules\OpenElis\Client\CatalogApiClient;

// ---------------------------------------------------------------------------
// CLI / env
// ---------------------------------------------------------------------------
$host      = $argv[1] ?? getenv('OPENELIS_PROBE_HOST')    ?: 'https://127.0.0.1:8443';
$login     = $argv[2] ?? getenv('OPENELIS_PROBE_LOGIN');
$password  = $argv[3] ?? getenv('OPENELIS_PROBE_PASSWORD');

if ($login === false || $password === false) {
    fwrite(STDERR, "Usage: php probe_catalog_api.php <host> <login> <password>\n");
    fwrite(STDERR, "  or export OPENELIS_PROBE_HOST/OPENELIS_PROBE_LOGIN/OPENELIS_PROBE_PASSWORD\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Transport mirror of CatalogApiClient (origin + Host header derivation)
// ---------------------------------------------------------------------------
const DEFAULT_HOST_HEADER = 'elis.origen.ar';

function probeOrigin(string $remoteHost): string
{
    if (filter_var($remoteHost, FILTER_VALIDATE_URL)) {
        $p = parse_url($remoteHost);
        $port = isset($p['port']) ? (int)$p['port'] : null;
        // If the port belongs to external-fhir-api (8080, 8081, 8444), the
        // REST webapp is on the default origin (https://127.0.0.1:8443).
        if (in_array($port, [8080, 8081, 8444], true)) {
            return 'https://127.0.0.1:8443';
        }
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . ($port ? ':' . $port : '');
    }
    return 'https://127.0.0.1:8443';
}

function probeHostHeader(string $remoteHost): string
{
    if (filter_var($remoteHost, FILTER_VALIDATE_URL)) {
        $host = parse_url($remoteHost, PHP_URL_HOST);
        if ($host !== false && $host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return $host;
        }
    }
    return DEFAULT_HOST_HEADER;
}

function rawRequest(string $url, string $login, string $password, string $hostHeader): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $hostHeader,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_USERPWD => $login . ':' . $password,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $body = curl_exec($ch);
    if (curl_errno($ch)) {
        $err = curl_error($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['status' => $status, 'body' => $body === false ? '' : $body];
}

// ---------------------------------------------------------------------------
// Reporting helpers
// ---------------------------------------------------------------------------
$discrepancies = 0;
function line(string $s = ''): void { echo $s . "\n"; }
function disc(string $msg): void { global $discrepancies; $discrepancies++; line('  ✗ DISCREPANCY   ' . $msg); }
function ok(string $msg): void { line('  ✓ ' . $msg); }
function typeOf($v): string
{
    if (is_bool($v)) { return 'bool(' . ($v ? 'true' : 'false') . ')'; }
    if ($v === null) { return 'null'; }
    if (is_int($v)) { return 'int(' . $v . ')'; }
    if (is_float($v)) { return 'float(' . $v . ')'; }
    if (is_string($v)) { return 'string(' . strlen($v) . ')' . (strlen($v) > 40 ? ' "' . mb_substr($v, 0, 37) . '..."' : ' "' . $v . '"'); }
    if (is_array($v)) { return 'array[' . count($v) . ']'; }
    return gettype($v);
}

/**
 * Inspect top-level keys of a decoded JSON document.
 */
function dumpTopLevel(array $doc, string $label): void
{
    line("== $label ==");
    if ($doc === []) {
        line('  (empty object/array)');
        return;
    }
    if (array_keys($doc) === range(0, count($doc) - 1)) {
        line('  root is a bare LIST of ' . count($doc) . ' items');
        if (isset($doc[0]) && is_array($doc[0])) {
            dumpItemKeys($doc[0], '  first item');
        }
        return;
    }
    foreach ($doc as $k => $v) {
        $val = is_array($v) ? 'array[' . count($v) . ']' : typeOf($v);
        line(sprintf('  %-28s %s', $k, $val));
    }
}

/**
 * Given a raw JSON document, extract the "item collection" the same way the
 * client does (candidate collection keys) and report which one matched.
 */
function findCollection(array $doc, array $candidateKeys): array
{
    foreach ($candidateKeys as $key) {
        if (isset($doc[$key]) && is_array($doc[$key])) {
            $total = isset($doc['total']) ? (int)$doc['total'] : null;
            if ($total === 0) {
                $total = null;
            }
            return ['key' => $key, 'items' => $doc[$key], 'total' => $total];
        }
    }
    if ($doc !== [] && array_keys($doc) === range(0, count($doc) - 1)) {
        return ['key' => '(bare list)', 'items' => $doc, 'total' => null];
    }
    return ['key' => null, 'items' => [], 'total' => null];
}

/**
 * Compact "k1=type, k2=type" listing of a map's first-level keys.
 */
function mapTypes(array $a): string
{
    $parts = [];
    foreach ($a as $k => $v) {
        $parts[] = $k . '=' . (is_array($v) ? 'array[' . count($v) . ']' : typeOf($v));
    }
    return implode(', ', $parts) . '  (n=' . count($a) . ')';
}

/**
 * Compare an item against the candidate-key lists the service parses with
 * (CatalogImportService::pick / pickStr / testName). Verdict: which code key /
 * name key won, whether the active flag is present in a recognizable shape,
 * and the real shape of the `localization` object.
 */
function dumpItemKeys(array $item, string $context): void
{
    $codeKeys = ['test_id', 'testId', 'id'];
    $nameKeys = ['test_name', 'testName', 'name', 'name_en', 'name_es', 'localization'];
    $support  = ['loinc', 'sampleType', 'testUnit', 'panel', 'uom', 'active', 'testSortOrder'];

    foreach ([$codeKeys, $nameKeys] as $keys) {
        $label = $keys === $codeKeys ? 'code' : 'name';
        $found = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $item)) {
                $found[] = "$k=" . typeOf($item[$k]);
            }
        }
        if ($found !== []) {
            ok("$context: $label key found -> " . implode(', ', $found));
        } else {
            disc("$context: none of " . implode('|', $keys) . " present");
        }
    }

    foreach ($support as $k) {
        if (array_key_exists($k, $item)) {
            ok("$context: $k present: " . typeOf($item[$k]));
        } else {
            disc("$context: expected field `$k` NOT present");
        }
    }

    $active = $item['active'] ?? null;
    if ($active !== null && !is_bool($active) && !is_numeric($active)
        && !in_array(strtolower(trim((string)$active)), ['active', 'not active'], true)) {
        disc("$context: `active` has an unrecognized shape (" . typeOf($active) . ')');
    }

    $loc = $item['localization'] ?? null;
    if (is_array($loc)) {
        line("$context: localization shape -> " . mapTypes($loc));
        foreach (['localizedNames', 'names'] as $m) {
            if (isset($loc[$m]) && is_array($loc[$m])) {
                line("$context: localization.$m        -> " . mapTypes($loc[$m]));
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Probe
// ---------------------------------------------------------------------------
$origin     = probeOrigin($host);
$hostHeader = probeHostHeader($host);
$url        = $origin . '/OpenELIS-Global/rest/TestCatalog';
line('=== RAW probe: ' . $url . '  (Host: ' . $hostHeader . ') ===');
line('');

$raw = rawRequest($url, $login, $password, $hostHeader);
if ($raw['status'] >= 400) {
    line('✗ /rest/TestCatalog HTTP ' . $raw['status'] . ': ' . mb_substr($raw['body'], 0, 400));
    exit(1);
}
$doc = json_decode($raw['body'], true);
if (!is_array($doc)) {
    disc('/rest/TestCatalog response is not JSON: ' . mb_substr($raw['body'], 0, 200));
    $doc = [];
}
dumpTopLevel($doc, '/rest/TestCatalog');
$catalog = findCollection($doc, ['testCatalogList', 'content', 'records', 'items', 'tests', 'testItems', 'elements', 'rows']);
line('  catalog collection key: ' . ($catalog['key'] ?? 'MISSING'));
if ($catalog['key'] === null && $catalog['items'] === []) {
    disc('/rest/TestCatalog has no recognizable collection');
    exit(1);
}
if ($catalog['items'] !== []) {
    dumpItemKeys($catalog['items'][0], 'test[0]');
    line('  catalog count: ' . count($catalog['items']) . ' (all tests: active + inactive)');
}

// ---------------------------------------------------------------------------
// Active-only view and comparison against the real client
// ---------------------------------------------------------------------------
$active = array_values(array_filter((array)$catalog['items'], static function ($t): bool {
    if (!is_array($t)) {
        return false;
    }
    $flag = $t['active'] ?? null;
    if (is_bool($flag)) {
        return $flag;
    }
    if (is_numeric($flag)) {
        return (int)$flag === 1;
    }
    return strtolower(trim((string)$flag)) === 'active';
}));
line('  active tests: ' . count($active) . ' of ' . count($catalog['items']));

$rawIds = [];
foreach ((array)$catalog['items'] as $t) {
    if (!is_array($t)) {
        continue;
    }
    $id = $t['test_id'] ?? $t['testId'] ?? $t['id'] ?? null;
    if ($id !== null) {
        $rawIds[(string)$id] = true;
    }
}

line('');
line('== CatalogApiClient (real client code) ==');
$client = new CatalogApiClient($host, $login, $password);
try {
    $cliCatalog = $client->listCatalog();
    line('  listCatalog          -> ' . count($cliCatalog) . ' raw items (active + inactive)');
} catch (Throwable $e) {
    disc('listCatalog threw: ' . $e->getMessage());
    exit(1);
}

$cliIds = [];
foreach ((array)$cliCatalog as $t) {
    $id = $t['test_id'] ?? $t['testId'] ?? $t['id'] ?? null;
    if ($id !== null) {
        $cliIds[(string)$id] = true;
    }
}
if ($rawIds !== [] && $cliIds !== []) {
    $missing = array_diff_key($rawIds, $cliIds);
    $extra   = array_diff_key($cliIds, $rawIds);
    if ($missing === [] && $extra === []) {
        ok('client test-id set matches raw catalog set exactly (' . count($cliIds) . ' ids)');
    } else {
        if ($missing !== []) { disc((count($missing)) . ' ids missing from client list'); }
        if ($extra  !== []) { disc((count($extra)) . ' extra ids in client list'); }
    }
} else {
    disc('could not compare id sets (raw=' . count($rawIds) . ', client=' . count($cliIds) . ')');
}

line('');
if ($discrepancies === 0) {
    line('PROBE OK — no discrepancies. Parsing matches the real server.');
    exit(0);
}
line($discrepancies . ' DISCREPANCY(IES) FOUND');
exit(1);