<?php

namespace OpenEMR\Modules\OpenElis\Client;

/**
 * HTTP client for OpenELIS Global 2 FHIR R4 API (HAPI FHIR 7.0.2).
 *
 * All requests go through the internal loopback address (127.0.0.1:8443)
 * with a Host header override for Docker routing. SSL verification is
 * disabled because this is trusted loopback traffic with a self-signed
 * certificate — never exposed to the public internet.
 */
class OpenElisApiClient
{
    private const DEFAULT_HOST_HEADER = 'elis.origen.ar';
    private const DEFAULT_ORIGIN = 'https://127.0.0.1:8443';
    private const FHIR_BASE_PATH = '/OpenELIS-Global/fhir';

    private string $baseUrl;
    private string $login;
    private string $password;
    private string $hostHeader;

    public function __construct(string $remoteHost, string $login, string $password)
    {
        $this->login = $login;
        $this->password = $password;
        $this->hostHeader = self::hostHeaderFromRemoteHost($remoteHost);
        $this->baseUrl = self::buildFhirBaseUrl($remoteHost);
    }

    /**
     * Choose the Host header used for Docker routing. When the remote_host
     * points at a real hostname (not loopback), we honor it; loopback
     * addresses fall back to the configured site name (elis.origen.ar).
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

    /**
     * Build the FHIR base URL ensuring the '/OpenELIS-Global/fhir/' path is present.
     */
    private static function buildFhirBaseUrl(string $remoteHost): string
    {
        $remoteHost = trim($remoteHost);
        if (empty($remoteHost)) {
            return self::DEFAULT_ORIGIN . self::FHIR_BASE_PATH . '/';
        }

        // If user already specified a URL containing '/fhir'
        if (str_contains($remoteHost, '/fhir')) {
            return rtrim($remoteHost, '/') . '/';
        }

        if (filter_var($remoteHost, FILTER_VALIDATE_URL)) {
            $p = parse_url($remoteHost);
            $scheme = $p['scheme'] ?? 'https';
            $host = $p['host'] ?? '127.0.0.1';
            $port = isset($p['port']) ? ':' . $p['port'] : '';
            $path = trim($p['path'] ?? '', '/');

            if (str_contains($path, 'OpenELIS-Global')) {
                return $scheme . '://' . $host . $port . '/' . $path . '/fhir/';
            }

            return $scheme . '://' . $host . $port . self::FHIR_BASE_PATH . '/';
        }

        return rtrim($remoteHost, '/') . self::FHIR_BASE_PATH . '/';
    }

    /**
     * Fetch a single FHIR resource by its logical id.
     *
     * @param string $resourceType  FHIR resource type (e.g. "Patient", "Observation")
     * @param string $id            Logical id (uuid)
     * @return array|null           The FHIR resource, or null if not found
     */
    public function fetchResource(string $resourceType, string $id): ?array
    {
        $response = $this->request('GET', $resourceType . '/' . $id);

        if ($response['status'] >= 400) {
            return null;
        }

        $resource = json_decode($response['body'], true);
        return is_array($resource) ? $resource : null;
    }

    /**
     * Search DiagnosticReports that are based on a ServiceRequest (chained
     * FHIR search). Each report carries the lab results for that test line.
     *
     * @param string $serviceRequestRef  e.g. "ServiceRequest/<uuid>"
     * @return array                     List of DiagnosticReport resources (may be empty)
     */
    public function findDiagnosticReportsByServiceRequest(string $serviceRequestRef): array
    {
        $response = $this->request('GET', 'DiagnosticReport', [
            'based-on' => $serviceRequestRef,
        ]);

        if ($response['status'] >= 400) {
            return [];
        }

        $bundle = json_decode($response['body'], true);
        if (!is_array($bundle)) {
            return [];
        }

        $reports = [];
        foreach (($bundle['entry'] ?? []) as $entry) {
            $resource = $entry['resource'] ?? null;
            if (!empty($resource['resourceType']) && $resource['resourceType'] === 'DiagnosticReport') {
                $reports[] = $resource;
            }
        }

        return $reports;
    }

    /**
     * List recently-issued diagnostic reports (probe/audit helper).
     *
     * @param int $count  Max reports to return
     * @return array      List of DiagnosticReport resources (may be empty)
     */
    public function listDiagnosticReports(int $count = 20): array
    {
        $response = $this->request('GET', 'DiagnosticReport', [
            '_count' => $count,
            '_sort' => '-issued',
        ]);

        if ($response['status'] >= 400) {
            return [];
        }

        $bundle = json_decode($response['body'], true);
        if (!is_array($bundle)) {
            return [];
        }

        $reports = [];
        foreach (($bundle['entry'] ?? []) as $entry) {
            $resource = $entry['resource'] ?? null;
            if (!empty($resource['resourceType']) && $resource['resourceType'] === 'DiagnosticReport') {
                $reports[] = $resource;
            }
        }

        return $reports;
    }

    /**
     * Search Observations referenced by a DiagnosticReport. Tries FHIR
     * _include first (single search, no extra round-trips); if the server does
     * not honor it, falls back to fetching each observation reference.
     *
     * @param array $report  A DiagnosticReport resource
     * @return array         List of Observation resources
     */
    public function fetchReportObservations(array $report): array
    {
        $reportId = $report['id'] ?? '';
        if ($reportId !== '') {
            $response = $this->request('GET', 'DiagnosticReport/' . $reportId, [
                '_include' => 'DiagnosticReport:result',
            ]);
            if ($response['status'] < 400) {
                $bundle = json_decode($response['body'], true);
                if (is_array($bundle)) {
                    $observations = [];
                    foreach (($bundle['entry'] ?? []) as $entry) {
                        $resource = $entry['resource'] ?? null;
                        if (($resource['resourceType'] ?? '') === 'Observation') {
                            $observations[] = $resource;
                        }
                    }
                    if ($observations) {
                        return $observations;
                    }
                }
            }
        }

        // Fallback: fetch each observation reference individually.
        $observations = [];
        foreach (($report['result'] ?? []) as $ref) {
            $got = $this->fetchResourceByReference($ref);
            if ($got !== null) {
                $observations[] = $got;
            }
        }

        return $observations;
    }

    /**
     * Resolve a relative FHIR reference (e.g. "Observation/<uuid>" or
     * "Observation/123") to its resource; handles contained resources and
     * absolute references on the loopback server.
     */
    public function fetchResourceByReference(array $ref): ?array
    {
        $reference = $ref['reference'] ?? '';
        if (empty($reference)) {
            return null;
        }

        // Relative reference like "Observation/<uuid>"
        if (preg_match('#^([A-Za-z]+)/(.+)$#', $reference, $m)) {
            return $this->fetchResource($m[1], $m[2]);
        }

        return null;
    }

    /**
     * Find a Patient in OpenELIS by their national identifier (pubpid).
     *
     * @param string $pubpid  The patient's external/public identifier
     * @return array|null     The FHIR Patient resource if found, null otherwise
     */
    public function findPatientByIdentifier(string $pubpid): ?array
    {
        $pubpid = trim($pubpid);
        if ($pubpid === '') {
            return null;
        }

        // Try qualified identifier system first
        $system = 'http://openelis-global.org/pat_nationalId';
        $response = $this->request('GET', 'Patient', [
            'identifier' => $system . '|' . $pubpid,
        ]);
        error_log("OpenELIS findPatientByIdentifier($system|$pubpid) HTTP {$response['status']}: " . substr($response['body'], 0, 250));

        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && !empty($bundle['entry'][0]['resource'])) {
                return $bundle['entry'][0]['resource'];
            }
        }

        // Try OpenEMR system identifier
        $oeSystem = 'http://openemr.org/fhir/patient-id';
        $response = $this->request('GET', 'Patient', [
            'identifier' => $oeSystem . '|' . $pubpid,
        ]);
        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && !empty($bundle['entry'][0]['resource'])) {
                return $bundle['entry'][0]['resource'];
            }
        }

        // Fallback: search identifier without system
        $response = $this->request('GET', 'Patient', [
            'identifier' => $pubpid,
        ]);
        error_log("OpenELIS findPatientByIdentifier(raw=$pubpid) HTTP {$response['status']}: " . substr($response['body'], 0, 250));

        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && !empty($bundle['entry'][0]['resource'])) {
                return $bundle['entry'][0]['resource'];
            }
        }

        return null;
    }

    /**
     * Find a Patient in OpenELIS by name.
     */
    public function findPatientByName(string $family, string $given): ?array
    {
        $family = trim($family);
        $given = trim($given);
        if ($family === '' && $given === '') {
            return null;
        }

        $params = [];
        if ($family !== '') {
            $params['family'] = $family;
        }
        if ($given !== '') {
            $params['given'] = $given;
        }

        $response = $this->request('GET', 'Patient', $params);
        error_log("OpenELIS findPatientByName(family=$family, given=$given) HTTP {$response['status']}: " . substr($response['body'], 0, 250));

        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && !empty($bundle['entry'][0]['resource'])) {
                return $bundle['entry'][0]['resource'];
            }
        }

        // Fallback: search general 'name'
        $fullName = trim($family . ' ' . $given);
        $response = $this->request('GET', 'Patient', ['name' => $fullName]);
        error_log("OpenELIS findPatientByName(name=$fullName) HTTP {$response['status']}: " . substr($response['body'], 0, 250));

        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && !empty($bundle['entry'][0]['resource'])) {
                return $bundle['entry'][0]['resource'];
            }
        }

        return null;
    }

    /**
     * Fetch the most recently created or updated Patient in OpenELIS.
     */
    public function fetchLatestPatient(): ?array
    {
        $response = $this->request('GET', 'Patient', [
            '_count' => 1,
            '_sort' => '-_lastUpdated',
        ]);
        error_log("OpenELIS fetchLatestPatient() HTTP {$response['status']}: " . substr($response['body'], 0, 250));

        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && !empty($bundle['entry'][0]['resource'])) {
                return $bundle['entry'][0]['resource'];
            }
        }

        return null;
    }

    /**
     * Fetch the most recently created or updated Practitioner in OpenELIS.
     */
    public function fetchLatestPractitioner(): ?array
    {
        $response = $this->request('GET', 'Practitioner', [
            '_count' => 1,
            '_sort' => '-_lastUpdated',
        ]);

        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && !empty($bundle['entry'][0]['resource'])) {
                return $bundle['entry'][0]['resource'];
            }
        }

        return null;
    }

    /**
     * Find a Practitioner in OpenELIS by NPI or name.
     *
     * @param string|null $npi   NPI number (preferred lookup)
     * @param string      $lname Last name
     * @param string      $fname First name
     * @return array|null        The FHIR Practitioner resource if found, null otherwise
     */
    public function findPractitioner(?string $npi, string $lname, string $fname): ?array
    {
        // Try NPI first
        if (!empty($npi)) {
            $response = $this->request('GET', 'Practitioner', [
                'identifier' => 'http://hl7.org/fhir/sid/us-npi|' . $npi,
            ]);

            if ($response['status'] < 400) {
                $bundle = json_decode($response['body'], true);
                if (is_array($bundle) && ($bundle['total'] ?? 0) > 0) {
                    return $bundle['entry'][0]['resource'] ?? null;
                }
            }
        }

        // Fallback: search by name
        $response = $this->request('GET', 'Practitioner', [
            'family' => $lname,
            'given' => $fname,
        ]);

        if ($response['status'] < 400) {
            $bundle = json_decode($response['body'], true);
            if (is_array($bundle) && ($bundle['total'] ?? 0) > 0) {
                return $bundle['entry'][0]['resource'] ?? null;
            }
        }

        return null;
    }

    /**
     * Create or update a FHIR resource via POST.
     *
     * @param array $resource  FHIR resource (must include 'resourceType')
     * @return array           The created/updated resource with server-assigned 'id'
     */
    public function createResource(array $resource): array
    {
        $resourceType = $resource['resourceType'];
        $response = $this->request('POST', $resourceType, [], $resource);

        if ($response['status'] >= 400) {
            throw new OpenElisApiException($response['status'], $response['body']);
        }

        // Try to extract ID from Location header first, then from response body
        $id = self::extractIdFromLocation($response['headers']['location'] ?? '', $resourceType);
        if (!$id) {
            $body = json_decode($response['body'], true);
            $id = $body['id'] ?? null;
        }

        if (empty($id)) {
            // When status is 201/200, return the resource without id rather than failing immediately,
            // allowing the caller to resolve the id via search if Location was omitted by proxy/server.
            if ($response['status'] === 200 || $response['status'] === 201) {
                return $resource;
            }

            error_log("OpenElisApiClient::createResource({$resourceType}) failed to find ID. Status: {$response['status']}, Location: " . ($response['headers']['location'] ?? 'none') . ", Headers: " . json_encode($response['headers']));
            throw new OpenElisApiException(
                $response['status'],
                "Failed to obtain ID for created {$resourceType}. Location: " . ($response['headers']['location'] ?? 'none') . ". Server response: " . substr($response['body'], 0, 300)
            );
        }

        $resource['id'] = $id;

        return $resource;
    }

    /**
     * Submit a FHIR Transaction Bundle.
     *
     * @param array $bundle  FHIR Bundle with type = "transaction"
     * @return array         The response Bundle with operation results
     */
    public function createBundle(array $bundle): array
    {
        $response = $this->request('POST', '', [], $bundle);

        if ($response['status'] >= 400) {
            throw new OpenElisApiException($response['status'], $response['body']);
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || empty($decoded['resourceType']) || $decoded['resourceType'] !== 'Bundle') {
            throw new OpenElisApiException(
                $response['status'],
                "Invalid response from OpenELIS: expected FHIR Bundle, got: " . substr($response['body'], 0, 300)
            );
        }

        return $decoded;
    }

    /**
     * Execute an HTTP request against the OpenELIS FHIR endpoint.
     *
     * @param string     $method   HTTP method (GET, POST, PUT)
     * @param string     $path     Resource path (e.g. "Patient", "ServiceRequest")
     * @param array      $params   Query parameters (GET only)
     * @param array|null $body     Request body (JSON-encoded for POST/PUT)
     * @return array               ['status' => int, 'body' => string, 'headers' => array]
     */
    private function request(string $method, string $path, array $params = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,

            // Basic Auth
            CURLOPT_USERPWD => $this->login . ':' . $this->password,

            // Headers — Host is derived from remote_host, not hardcoded
            CURLOPT_HTTPHEADER => [
                'Host: ' . $this->hostHeader,
                'Content-Type: application/fhir+json',
                'Accept: application/fhir+json',
                'Prefer: return=representation',
            ],

            // SSL: disabled because the FHIR endpoint commonly runs on loopback
            // with a self-signed certificate (127.0.0.1:8443). Traffic never
            // leaves the server.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,

            // Capture response headers to read Location header
            CURLOPT_HEADER => true,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            throw new \RuntimeException("cURL error: $error");
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        // Parse response headers (handling CRLF, LF, or CR)
        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($headerStr)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        // Direct regex fallback for Location header across the entire header string
        if (empty($headers['location'])) {
            if (preg_match('/^location:\s*(.+)$/im', $headerStr, $locMatch)) {
                $headers['location'] = trim($locMatch[1]);
            }
        }

        // HTTP 3xx redirect indicates an issue with the base URL or proxy configuration
        if ($statusCode >= 300 && $statusCode < 400) {
            $redirectUrl = $headers['location'] ?? 'unknown';
            throw new OpenElisApiException(
                $statusCode,
                "Unexpected HTTP redirect ($statusCode) to '$redirectUrl'. Check OpenELIS FHIR endpoint ($url)."
            );
        }

        return [
            'status' => $statusCode,
            'body' => $responseBody,
            'headers' => $headers,
        ];
    }

    public static function extractIdFromLocation(?string $location, string $expectedResourceType = ''): ?string
    {
        if (empty($location)) {
            return null;
        }

        $clean = '/' . ltrim($location, '/');
        if ($expectedResourceType !== '') {
            if (preg_match('~/' . preg_quote($expectedResourceType, '~') . '/([^/_?#]+)~', $clean, $m)) {
                return $m[1];
            }
            return null;
        }

        if (preg_match('~/([A-Z][a-zA-Z]+)/([^/_?#]+)~', $clean, $m)) {
            return $m[2];
        }

        return null;
    }
}
