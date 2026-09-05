<?php

namespace OpenEMR\Modules\OpenElis\Catalog;

/**
 * Reads the OpenELIS test catalog over the HTTPS REST API.
 *
 * WHY REST AND NOT DIRECT SQL
 *   The lab provides only an API user/password, not database credentials.
 *   The OpenELIS FHIR API (HAPI) on this instance does not expose a
 *   test-catalog resource (ObservationDefinition is unknown; Observation and
 *   ServiceRequest are for results/orders only). The standalone REST endpoint
 *   GET /OpenELIS-Global/rest/TestNamesProvider?testId={id} returns a test's
 *   name (Spanish/English) for a single numeric id; testId=all returns HTTP 500.
 *   The catalog is therefore built by iterating a numeric id range.
 *
 * AUTHENTICATION / TRANSPORT
 *   Follows the same pattern as the FHIR client (OpenElisApiClient): requests
 *   go through the internal loopback 127.0.0.1:8443 with a Host header override
 *   for Docker routing, using Basic Auth with the lab provider's credentials
 *   from procedure_providers (login / password). SSL verification is disabled
 *   because this is trusted loopback traffic with a self-signed certificate —
 *   never exposed to the public internet.
 *
 * MIRROR
 *   The synchronized id -> name results are stored in the local table
 *   mod_openelis_test_catalog so the mapping page can autosuggest without
 *   querying OpenELIS on every keystroke. Only reads are performed against
 *   OpenELIS; writes go to the local mirror table.
 */
class OpenElisCatalog
{
    private const REST_PATH = '/OpenELIS-Global/rest/TestNamesProvider';
    private const DEFAULT_HOST_HEADER = 'elis.origen.ar';
    private const DEFAULT_CATALOG_BASE = 'https://127.0.0.1:8443';

    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? self::readConfigFromDb();
    }

    /**
     * Load module config key/values (id probing range) from mod_openelis_config.
     *
     * @return array  Keys => values
     */
    private static function readConfigFromDb(): array
    {
        $cfg = [];
        $rs = sqlStatement("SELECT cfg_name, cfg_value FROM mod_openelis_config");
        while ($row = sqlFetchArray($rs)) {
            $cfg[$row['cfg_name']] = $row['cfg_value'];
        }
        return $cfg;
    }

    /**
     * Resolve the catalog credentials.
     *
     * Precedence:
     *   1. api_user / api_pass from mod_openelis_config (the dedicated API
     *      credential the lab provides, if any).
     *   2. Otherwise the active WS lab provider from procedure_providers
     *      (login / password, protocol = 'WS'), which the send flow also uses.
     *
     * @return array ['url_base' => string, 'login' => string, 'password' => string]
     * @throws \RuntimeException  If no credentials can be resolved
     */
    private function resolveCredentials(): array
    {
        $apiUser = trim((string)($this->config['api_user'] ?? ''));
        $apiPass = (string)($this->config['api_pass'] ?? '');

        $remoteHost = null;
        $login = null;
        $password = null;

        if ($apiUser !== '') {
            $login = $apiUser;
            $password = $apiPass;
        } else {
            $provider = sqlQuery(
                "SELECT * FROM procedure_providers WHERE protocol = 'WS' AND active = 1 ORDER BY ppid LIMIT 1"
            );
            if (empty($provider)) {
                throw new \RuntimeException(
                    "No API credentials configured and no active Web Services (WS) lab provider found. "
                    . "Set them in OpenELIS Settings, or configure a WS provider in the lab provider settings."
                );
            }
            $login = $provider['login'];
            $password = $provider['password'];
            $remoteHost = $provider['remote_host'];
        }

        if (empty($login)) {
            throw new \RuntimeException("No OpenELIS API user configured for the catalog.");
        }

        // The origin (scheme://host[:port]) comes from the provider's remote_host
        // when present (the REST path shares the same loopback server as FHIR);
        // otherwise the catalog is assumed to run on the same loopback endpoint.
        $urlBase = $remoteHost
            ? self::originFromRemoteHost($remoteHost)
            : self::DEFAULT_CATALOG_BASE;

        return [
            'url_base' => $urlBase,
            'login' => $login,
            'password' => $password,
        ];
    }

    /**
     * Derive the server origin (scheme://host[:port]) from a full URL such as
     * the provider's remote_host (e.g. https://127.0.0.1:8443/api/...).
     *
     * @param string $remoteHost
     * @return string  e.g. https://127.0.0.1:8443
     */
    private static function originFromRemoteHost(string $remoteHost): string
    {
        if (filter_var($remoteHost, FILTER_VALIDATE_URL)) {
            $p = parse_url($remoteHost);
            $scheme = $p['scheme'] ?? 'https';
            $host = $p['host'] ?? '';
            $port = isset($p['port']) ? ':' . $p['port'] : '';
            return $scheme . '://' . $host . $port;
        }
        // Not a full URL: assume scheme-less host[:port]
        return 'https://' . rtrim($remoteHost, '/');
    }

    /**
     * Whether the probing id range has been configured (always effectively yes,
     * since sensible defaults exist). Kept for API parity.
     */
    public function isConfigured(): bool
    {
        $min = (int)($this->config['catalog_min_id'] ?? 1);
        $max = (int)($this->config['catalog_max_id'] ?? 500);
        return $min >= 1 && $max >= $min;
    }

    /**
     * Fetch one test's name payload from the REST endpoint.
     *
     * @param string   $testId   Numeric test id to probe
     * @param string   $urlBase  Server origin, e.g. https://127.0.0.1:8443
     * @param string   $login
     * @param string   $password
     * @param int|null &$status  Receives the HTTP status
     * @return array|null  ['name_es'=>..., 'name_en'=>...] or null if not a test
     */
    private function fetchTestName(string $testId, string $urlBase, string $login, string $password, ?int &$status): ?array
    {
        $url = $urlBase . self::REST_PATH . '?testId=' . rawurlencode($testId);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERPWD => $login . ':' . $password,
            CURLOPT_HTTPHEADER => [
                'Host: ' . self::DEFAULT_HOST_HEADER,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $body = curl_exec($ch);
        $errCode = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $status = $httpCode;
        if ($errCode !== 0 || $httpCode !== 200 || $body === false || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $name = $data['name'] ?? [];
        $es = $name['spanish'] ?? null;
        $en = $name['english'] ?? null;
        if (!$es && !$en) {
            return null;
        }

        return ['name_es' => $es ?: '', 'name_en' => $en ?: ''];
    }

    /**
     * Probe IDs in the configured range and rebuild the local mirror catalog.
     *
     * @return array ['added' => int, 'errors' => int, 'stopped_by_gap' => bool, 'max_probed' => int]
     * @throws \RuntimeException  If transport or auth fails (e.g. not a test server)
     */
    public function syncCatalog(): array
    {
        $creds = $this->resolveCredentials();
        $urlBase = $creds['url_base'];
        $login = $creds['login'];
        $password = $creds['password'];

        $minId = max(1, (int)($this->config['catalog_min_id'] ?? 1));
        $maxId = max($minId, (int)($this->config['catalog_max_id'] ?? 500));

        $added = 0;
        $errors = 0;
        $consecutiveGaps = 0;
        $lastProbed = 0;

        for ($id = $minId; $id <= $maxId; $id++) {
            $status = 0;
            $found = $this->fetchTestName((string)$id, $urlBase, $login, $password, $status);

            $lastProbed = $id;

            if ($found !== null) {
                $this->upsertTest($id, $found['name_es'], $found['name_en']);
                $added++;
                $consecutiveGaps = 0;
                continue;
            }

            $errors++;
            if ($status === 401 || $status === 403) {
                throw new \RuntimeException(
                    "OpenELIS catalog authentication failed (HTTP $status). Check the lab provider user/password."
                );
            }

            // Once we see a run of non-tests, the ids are exhausted; stop early.
            $consecutiveGaps++;
            if ($consecutiveGaps >= 50) {
                break;
            }
        }

        return [
            'added' => $added,
            'errors' => $errors,
            'stopped_by_gap' => $consecutiveGaps >= 50,
            'max_probed' => $lastProbed,
        ];
    }

    /**
     * Insert or update one catalog row in the local mirror.
     */
    private function upsertTest(int $testId, string $nameEs, string $nameEn): void
    {
        sqlStatement(
            "INSERT INTO mod_openelis_test_catalog (openelis_test_id, name_es, name_en)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name_es = VALUES(name_es), name_en = VALUES(name_en)",
            [(string)$testId, $nameEs, $nameEn]
        );
    }

    /**
     * Search the local mirror catalog (name or id), for mapping autosuggestion.
     *
     * @param string|null $search
     * @param int         $limit
     * @return array  Rows: openelis_test_id, name_es, name_en
     */
    public function searchTests(?string $search = null, int $limit = 200): array
    {
        $sql = "SELECT openelis_test_id, name_es, name_en
                FROM mod_openelis_test_catalog";
        $params = [];
        if ($search !== null && $search !== '') {
            $sql .= " WHERE openelis_test_id LIKE ? OR name_es LIKE ? OR name_en LIKE ?";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like];
        }
        $sql .= " ORDER BY name_es, name_en LIMIT " . (int)$limit;

        $rows = [];
        $rs = sqlStatement($sql, $params);
        while ($row = sqlFetchArray($rs)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Resolve the display name for a single OpenELIS test id from the mirror.
     *
     * @param string $testId
     * @return string|null
     */
    public function testNameById(string $testId): ?string
    {
        if ($testId === '') {
            return null;
        }
        $row = sqlQuery(
            "SELECT name_es, name_en FROM mod_openelis_test_catalog WHERE openelis_test_id = ?",
            [$testId]
        );
        if (!$row) {
            return null;
        }
        return $row['name_es'] ?: ($row['name_en'] ?: null);
    }
}
