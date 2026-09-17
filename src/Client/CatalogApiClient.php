<?php

namespace OpenEMR\Modules\OpenElis\Client;

/**
 * HTTP client for the OpenELIS Global 2 test-catalog REST API.
 *
 * ENDPOINTS (require an OpenELIS user with the ADMIN role — different from the
 * Analyser Import user used for FHIR orders):
 *
 *   GET /OpenELIS-Global/rest/TestCatalog
 *
 *   The classic catalog endpoint: returns the WHOLE test catalog in a single
 *   non-paginated JSON document ({ testCatalogList: [...], testSectionList:
 *   [...] }). Each entry carries id, localized name, section (testUnit),
 *   sampleType, loinc, uom and the active/orderable flags. OpenELIS does NOT
 *   expose panels as a list over REST — panel membership only appears as a
 *   display string per test — so the catalog import groups tests by section.
 *
 * WHY A SEPARATE CLIENT (AND NOT OpenElisApiClient)
 *   OpenElisApiClient is bound to FHIR (Content-Type: application/fhir+json,
 *   base path /OpenELIS-Global/fhir/, Host header fixed to elis.origen.ar).
 *   The catalog API is plain REST JSON under the /OpenELIS-Global/rest/
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
    private const BASE_PATH = '/OpenELIS-Global/rest';

    private string $baseUrl;
    private string $login;
    private string $password;
    private string $hostHeader;

    public function __construct(string $remoteHost, string $login, string $password)
    {
        $this->baseUrl = self::originFromRemoteHost($remoteHost) . self::BASE_PATH . '/';
        $this->login = $login;
        $this->password = $password;
        $this->hostHeader = self::hostHeaderFromRemoteHost($remoteHost);
    }

    /**
     * Fetch the full test catalog in one shot.
     *
     * The classic catalog endpoint returns the entire catalog as one JSON
     * document ({ testCatalogList, testSectionList }). No pagination and no
     * separate panels endpoint: every active AND inactive test is present, and
     * the caller filters by the `active` flag ("Active"/"Not active") it
     * reports per entry.
     *
     * @return array  Raw catalog items as returned by the API (id/localization/
     *                testUnit/sampleType/loinc/uom/active/... keys)
     */
    public function listCatalog(): array
    {
        $data = $this->request('TestCatalog');
        return self::extractItems($data);
    }

    /**
     * Execute a GET against the catalog API.
     *
     * @param string $path   Path relative to .../rest/ (e.g. "TestCatalog")
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
            throw new \RuntimeException("OpenELIS catalog cURL error: $error");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

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
     * endpoint may use (envelope key, Spring Page wrapper, bare list, or empty).
     *
     * @param array $data
     * @return array
     */
    private static function extractItems(array $data): array
    {
        return self::extractPage($data)['items'];
    }

    /**
     * Normalize a response into ['items' => array, 'total' => ?int].
     *
     * The classic catalog endpoint nests its items under `testCatalogList`
     * (with a bare list accepted as well), while a Spring-style paged document
     * would carry them under `rows`/`content`/... next to a `total`. All the
     * candidate keys below are accepted so both shapes work.
     *
     * @param array $data
     * @return array
     */
    private static function extractPage(array $data): array
    {
        foreach (['testCatalogList', 'content', 'records', 'items', 'tests', 'testItems', 'elements', 'rows'] as $key) {
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