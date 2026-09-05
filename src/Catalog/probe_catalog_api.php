<?php

/**
 * Standalone probe for the OpenELIS Global 2 test-catalog REST API.
 *
 * Not part of the module repo. Runs against the REAL server with the catalog
 * ADMIN credentials and does three things:
 *
 *   1. Raw calls to the 3 endpoints, dumping the FIRST response's structure
 *      (keys present + value types).
 *   2. Explicit comparison against what CatalogApiClient / CatalogImportService
 *      expect to parse (fixed candidate-key lists), flagging DISCREPANCIES.
 *   3. Real pagination test: when the active-tests endpoint reports total > 500
 *      (pageSize), verifies our page-by-page loop in CatalogApiClient fetches
 *      every remaining page (not just the mock scenario).
 *
 * CREDENTIALS (never hardcoded):
 *     env  OPENELIS_PROBE_HOST | OPENELIS_PROBE_LOGIN | OPENELIS_PROBE_PASSWORD
 *   or argv: php probe_catalog_api.php <host> <login> <password> [pageSize]
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
$pageSize  = (int)($argv[4] ?? 500);

if ($login === false || $password === false) {
    fwrite(STDERR, "Usage: php probe_catalog_api.php <host> <login> <password> [pageSize]\n");
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
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
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
 * Compare an item against the candidate-key lists the service parses with
 * (CatalogImportService::pick). Verdict: which code key / name key won.
 */
function dumpItemKeys(array $item, string $context): void
{
    $codeKeys = ['test_id', 'testId', 'id'];
    $nameKeys = ['test_name', 'testName', 'name', 'name_en', 'name_es'];
    $support  = ['loinc', 'loinc_code', 'loincCode', 'errorCount', 'error_count', 'errors', 'findings'];

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

    $errorCount = $item['errorCount'] ?? $item['error_count'] ?? $item['errors'] ?? null;
    if ($errorCount !== null && !is_int($errorCount) && !is_string($errorCount)) {
        disc("$context: errorCount is not scalar (" . typeOf($errorCount) . ')');
    }

    $findings = $item['findings'] ?? null;
    if ($findings !== null && !is_array($findings)) {
        disc("$context: findings is " . typeOf($findings) . ' (service expects array)');
    }
}

// ---------------------------------------------------------------------------
// Probe
// ---------------------------------------------------------------------------
$origin     = probeOrigin($host);
$hostHeader = probeHostHeader($host);
$base       = $origin . '/OpenELIS-Global/rest/test-catalog/';
line('=== RAW probe: ' . $base . '  (Host: ' . $hostHeader . ') ===');
line('');

// -- 1. /panels?includeInactive=false ---------------------------------------
$url = $base . 'panels?includeInactive=false';
$raw = rawRequest($url, $login, $password, $hostHeader);
if ($raw['status'] >= 400) {
    line("✗ /panels HTTP " . $raw['status'] . ": " . mb_substr($raw['body'], 0, 400));
    exit(1);
}
$doc = json_decode($raw['body'], true);
if (!is_array($doc)) {
    disc('/panels response is not JSON: ' . mb_substr($raw['body'], 0, 200));
    $doc = [];
}
dumpTopLevel($doc, '/panels');
$panels = findCollection($doc, ['content', 'records', 'items', 'panels', 'elements']);
line('  panels collection key: ' . ($panels['key'] ?? 'MISSING'));
if ($panels['key'] === null && $panels['items'] === []) {
    disc('/panels has no recognizable collection');
} elseif ($panels['items'] !== []) {
    dumpItemKeys($panels['items'][0], 'panel[0]');
    line('  panels count: ' . count($panels['items']));
}
$panelItems = $panels['items'];

// -- 2. /panels/{id}/test-order (first panel) --------------------------------
$panelId = null;
$panelIdKeys = ['panel_id', 'id', 'guid', 'code'];
foreach ($panelItems as $p) {
    foreach ($panelIdKeys as $k) {
        if (!empty($p[$k])) {
            $panelId = (string)$p[$k];
            break 2;
        }
    }
}
line('');
if ($panelId === null) {
    disc('/panels: cannot determine a panel id for /test-order probe');
} else {
    $url = $base . 'panels/' . rawurlencode($panelId) . '/test-order';
    $raw = rawRequest($url, $login, $password, $hostHeader);
    if ($raw['status'] >= 400) {
        line("✗ /panels/$panelId/test-order HTTP " . $raw['status'] . ": " . mb_substr($raw['body'], 0, 400));
        exit(1);
    }
    $doc = json_decode($raw['body'], true);
    if (!is_array($doc)) {
        disc('/test-order response is not JSON');
        $doc = [];
    }
    dumpTopLevel($doc, "/panels/$panelId/test-order");
    $members = findCollection($doc, ['content', 'records', 'items', 'tests', 'testItems', 'elements']);
    line('  member collection key: ' . ($members['key'] ?? 'MISSING'));
    if ($members['key'] === null && $members['items'] === []) {
        disc('/test-order has no recognizable collection');
    } elseif ($members['items'] !== []) {
        dumpItemKeys($members['items'][0], 'member[0]');
        line('  members count: ' . count($members['items']));
    }
}

// -- 3. /tests?status=active&pageSize=N, including pagination loop ----------
line('');
$url = $base . 'tests?status=active&page=' . 0 . '&pageSize=' . $pageSize;
$raw = rawRequest($url, $login, $password, $hostHeader);
if ($raw['status'] >= 400) {
    line("✗ /tests HTTP " . $raw['status'] . ": " . mb_substr($raw['body'], 0, 400));
    exit(1);
}
$doc = json_decode($raw['body'], true);
if (!is_array($doc)) {
    disc('/tests response is not JSON');
    $doc = [];
}
dumpTopLevel($doc, '/tests (page 0)');
$page = findCollection($doc, ['content', 'records', 'items', 'rows', 'tests', 'testItems', 'elements']);
line('  tests collection key: ' . ($page['key'] ?? 'MISSING') . '   total=' . var_export($page['total'], true));
if ($page['key'] === null && $page['items'] === []) {
    disc('/tests has no recognizable collection');
    exit(1);
}
if ($page['items'] !== []) {
    dumpItemKeys($page['items'][0], 'test[0]');
}

// -- Real pagination: fetch ALL pages manually and compare with the client ---
$all = [];
$total = $page['total'];
$maxPages = 100;
for ($p = 0; $p < $maxPages; $p++) {
    $url = $base . 'tests?status=active&page=' . $p . '&pageSize=' . $pageSize;
    $raw = rawRequest($url, $login, $password, $hostHeader);
    if ($raw['status'] >= 400) {
        disc('/tests page ' . $p . ' HTTP ' . $raw['status']);
        break;
    }
    $doc = json_decode($raw['body'], true);
    $pg = is_array($doc) ? findCollection($doc, ['content', 'records', 'items', 'rows', 'tests', 'testItems', 'elements']) : ['items' => [], 'total' => null];
    if ($total === null && $pg['total'] !== null) {
        $total = $pg['total'];
    }
    $all = array_merge($all, $pg['items']);
    if ($total !== null && count($all) >= $total) {
        break;
    }
    if (count($pg['items']) < $pageSize) {
        break;
    }
}
line('');
line("== PAGINATION (pageSize=$pageSize) ==");
line('  total reported by API:  ' . var_export($total, true));
line('  pages fetched in loop:  ' . ($p + 1));
line('  items collected:        ' . count($all));

if ($total !== null && count($all) > $pageSize) {
    ok('pagination stressed with real data (' . count($all) . ' > ' . $pageSize . ')');
} elseif ($total !== null && count($all) > 0) {
    line('  note: only ' . count($all) . " active tests — pagination not stressed (total <= pageSize), but the short-page stop worked");
}
if ($total !== null && count($all) < $total) {
    disc('collected ' . count($all) . ' of ' . $total . ' — page loop stopped early');
}

// -- Same data through CatalogApiClient (the real client) -------------------
line('');
line('== CatalogApiClient (real client code) ==');
$client = new CatalogApiClient($host, $login, $password);
$cPanels = $client->listPanels(false);
$cTests = null;
try {
    $cTests = $client->listActiveTests($pageSize);
    line('  listActiveTests      -> ' . count($cTests) . ' tests');
} catch (Throwable $e) {
    disc('listActiveTests threw: ' . $e->getMessage());
}
line('  listPanels            -> ' . count($cPanels) . ' panels');

$rawIds = [];
foreach ($all as $t) {
    $id = $t['test_id'] ?? $t['testId'] ?? $t['id'] ?? null;
    if ($id !== null) { $rawIds[(string)$id] = true; }
}
$cliIds = [];
foreach ((array)$cTests as $t) {
    $id = $t['test_id'] ?? $t['testId'] ?? $t['id'] ?? null;
    if ($id !== null) { $cliIds[(string)$id] = true; }
}
if ($rawIds !== [] && $cliIds !== []) {
    $missing = array_diff_key($rawIds, $cliIds);
    $extra   = array_diff_key($cliIds, $rawIds);
    if ($missing === [] && $extra === []) {
        ok('client test-id set matches raw paginated set exactly (' . count($cliIds) . ' ids)');
    } else {
        if ($missing !== []) { disc((count($missing)) . ' ids missing from client list'); }
        if ($extra  !== []) { disc((count($extra)) . ' extra ids in client list'); }
    }
}

if ($panelId !== null) {
    $cMembers = $client->listPanelTests($panelId);
    line('  listPanelTests(' . $panelId . ') -> ' . count($cMembers) . ' members');
    if ($members['items'] !== []) {
        $rawMemberIds = [];
        foreach ($members['items'] as $m) {
            $id = $m['test_id'] ?? $m['testId'] ?? $m['id'] ?? null;
            if ($id !== null) { $rawMemberIds[(string)$id] = true; }
        }
        $cliMemberIds = [];
        foreach ($cMembers as $m) {
            $id = $m['test_id'] ?? $m['testId'] ?? $m['id'] ?? null;
            if ($id !== null) { $cliMemberIds[(string)$id] = true; }
        }
        if ($rawMemberIds !== [] && $rawMemberIds == $cliMemberIds) {
            ok('client panel-member set matches raw set (' . count($cliMemberIds) . ' ids)');
        } else {
            disc('panel-member sets diverge between raw and client');
        }
    }
}

line('');
if ($discrepancies === 0) {
    line('PROBE OK — no discrepancies. Parsing matches the real server.');
    exit(0);
}
line($discrepancies . ' DISCREPANCY(IES) FOUND');
exit(1);