<?php

namespace OpenEMR\Modules\OpenElis\Client;

/**
 * HTTP client for the OpenELIS Global 2 test-catalog REST API.
 *
 * ENDPOINTS (require an OpenELIS user with the ADMIN role — different from the
 * Analyser Import user used for FHIR orders):
 *
 *   GET /OpenELIS-Global/rest/test-catalog/panels?includeInactive=false
 *   GET /OpenELIS-Global/rest/test-catalog/panels/{panelId}/test-order
 *   GET /OpenELIS-Global/rest/test-catalog/tests?status=active&pageSize=N
 *
 * WHY A SEPARATE CLIENT (AND NOT OpenElisApiClient)
 *   OpenElisApiClient is bound to FHIR (Content-Type: application/fhir+json,
 *   base path /OpenELIS-Global/fhir/, Host header fixed to elis.origen.ar).
 *   The test-catalog API is plain REST JSON under the /OpenELIS-Global/rest/
 *   path, uses application/json and — importantly — different credentials
 *   (the ADMIN catalog user). Keeping them apart avoids mixing credential
 *   sets between the send flow (Analyser Import) and the catalog import.
 *
 * TRANSPORT
 *   Same transport as the FHIR client: requests go to the internal loopback
 *   origin derived from the provider's remote_host with a Host header override
 *   for Docker routing, using Basic Auth, and SSL verification is disabled
 *   because this is trusted loopback traffic with a self-signed certificate.
 */
class CatalogApiClient
{
    private const DEFAULT_HOST_HEADER = 'elis.origen.ar';
    private const DEFAULT_ORIGIN = 'https://127.0.0.1:8443';
    private const BASE_PATH = '/OpenELIS-Global/rest/test-catalog';

    private string $baseUrl;
    private string $login;
    private string $password;
    private string $hostHeader;
    private int $maxPages = 100;

    public function __construct(string $remoteHost, string $login, string $password)
    {
        $this->baseUrl = self::originFromRemoteHost($remoteHost) . self::BASE_PATH . '/';
        $this->login = $login;
        $this->password = $password;
        $this->hostHeader = self::hostHeaderFromRemoteHost($remoteHost);
    }

    /**
     * List panels (optionally including inactive ones).
     *
     * @return array  Raw panel items as returned by the API (id/name/... keys)
     */
    public function listPanels(bool $includeInactive = false): array
    {
        $data = $this->request('panels', [
            'includeInactive' => $includeInactive ? 'true' : 'false',
        ]);
        return self::extractItems($data);
    }

    /**
     * List the ordered tests that belong to a single panel.
     *
     * @param string $panelId
     * @return array  Raw test items (testId/name/... keys)
     */
    public function listPanelTests(string $panelId): array
    {
        $data = $this->request('panels/' . rawurlencode($panelId) . '/test-order');
        return self::extractItems($data);
    }

    /**
     * List active tests, following pagination until every page is consumed.
     *
     * The endpoint returns a page-shaped JSON document (Spring-style) with a
     * `total` and a collection under one of {content, records, items, tests,
     * testItems, elements}. We loop page by page until we collected `total`
     * items (or a page comes back short when `total` is absent).
     *
     * @param int $pageSize
     * @return array  Raw test items (id/name/loinc/errorCount/findings/... keys)
     */
    public function listActiveTests(int $pageSize = 500): array
    {
        $all = [];
        $total = null;

        for ($page = 0; $page < $this->maxPages; $page++) {
            $data = $this->request('tests', [
                'status' => 'active',
                'page' => $page,
                'pageSize' => $pageSize,
            ]);

            $pageInfo = self::extractPage($data);
            $items = $pageInfo['items'];
            if ($total === null && $pageInfo['total'] !== null) {
                $total = $pageInfo['total'];
            }

            $all = array_merge($all, $items);

            // Stop when we have everything the API told us about, or when a
            // page came back short (not enough data to fill another page).
            if ($total !== null && count($all) >= $total) {
                break;
            }
            if (count($items) < $pageSize) {
                break;
            }
        }

        return $all;
    }

    /**
     * Execute a GET against the catalog API.
     *
     * @param string $path   Path relative to .../rest/test-catalog/
     * @param array  $params Query parameters
     * @return array         Decoded JSON, or [] on empty body
     */
    private function request(string $path, array $params = []): array
    {
        $url = $this->baseUrl . ltrim($path, '/');
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Host: ' . $this->hostHeader,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            // Loopback-only trusted traffic with a self-signed certificate.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $body = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("OpenELIS catalog cURL error: $error");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new OpenElisApiException($status, (string)$body);
        }

        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Pull the item collection out of a response in any of the shapes the
     * endpoint may use (Spring Page wrapper, bare list, or empty).
     *
     * @param array $data
     * @return array
     */
    private static function extractItems(array $data): array
    {
        return self::extractPage($data)['items'];
    }

    /**
     * Normalize a page-shaped response into ['items' => array, 'total' => ?int].
     *
     * @param array $data
     * @return array
     */
    private static function extractPage(array $data): array
    {
        foreach (['content', 'records', 'items', 'tests', 'testItems', 'elements'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $total = isset($data['total']) ? (int)$data['total'] : null;
                if ($total === 0) {
                    $total = null;
                }
                return ['items' => $data[$key], 'total' => $total];
            }
        }

        if (self::isList($data)) {
            return ['items' => $data, 'total' => null];
        }

        return ['items' => [], 'total' => null];
    }

    private static function isList(array $array): bool
    {
        if ($array === []) {
            return true;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * Derive the server origin (scheme://host[:port]) from a full URL such as
     * the provider's remote_host (e.g. https://127.0.0.1:8443/api/...).
     *
     * @param string $remoteHost
     * @return string
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
        return self::DEFAULT_ORIGIN;
    }

    /**
     * Choose the Host header used for Docker routing. When the remote_host
     * points at a real hostname (not the loopback), we honor it; loopback
     * addresses fall back to the configured site name (elis.origen.ar).
     *
     * @param string $remoteHost
     * @return string
     */
    private static function hostHeaderFromRemoteHost(string $remoteHost): string
    {
        if (filter_var($remoteHost, FILTER_VALIDATE_URL)) {
            $host = parse_url($remoteHost, PHP_URL_HOST);
            if ($host !== false && $host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                return $host;
            }
        }
        return self::DEFAULT_HOST_HEADER;
    }
}