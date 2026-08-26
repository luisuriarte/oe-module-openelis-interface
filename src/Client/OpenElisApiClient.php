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
    private string $baseUrl;
    private string $login;
    private string $password;

    public function __construct(string $remoteHost, string $login, string $password)
    {
        // Normalize: ensure trailing slash
        $this->baseUrl = rtrim($remoteHost, '/') . '/';
        $this->login = $login;
        $this->password = $password;
    }

    /**
     * Find a Patient in OpenELIS by their national identifier (pubpid).
     *
     * @param string $pubpid  The patient's external/public identifier
     * @return array|null     The FHIR Patient resource if found, null otherwise
     */
    public function findPatientByIdentifier(string $pubpid): ?array
    {
        $system = 'http://openelis-global.org/pat_nationalId';
        $response = $this->request('GET', 'Patient', [
            'identifier' => $system . '|' . $pubpid,
        ]);

        if ($response['status'] >= 400) {
            return null;
        }

        $bundle = json_decode($response['body'], true);
        if (!is_array($bundle) || ($bundle['total'] ?? 0) === 0) {
            return null;
        }

        return $bundle['entry'][0]['resource'] ?? null;
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
        $id = self::extractIdFromLocation($response['headers']['location'] ?? '');
        if (!$id) {
            $body = json_decode($response['body'], true);
            $id = $body['id'] ?? null;
        }

        if ($id) {
            $resource['id'] = $id;
        }

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

        return json_decode($response['body'], true) ?? [];
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
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,

            // Basic Auth
            CURLOPT_USERPWD => $this->login . ':' . $this->password,

            // Headers
            CURLOPT_HTTPHEADER => [
                'Host: elis.origen.ar',
                'Content-Type: application/fhir+json',
                'Accept: application/fhir+json',
            ],

            // SSL: disabled because the FHIR endpoint runs on 127.0.0.1:8443
            // with a self-signed certificate. This is loopback-only traffic
            // on the same server — it is never exposed to the public internet.
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
            curl_close($ch);
            throw new \RuntimeException("cURL error: $error");
        }

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        curl_close($ch);

        // Parse response headers
        $headers = [];
        foreach (explode("\r\n", trim($headerStr)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'status' => $statusCode,
            'body' => $responseBody,
            'headers' => $headers,
        ];
    }

    private static function extractIdFromLocation(?string $location): ?string
    {
        if (empty($location)) {
            return null;
        }

        // Location header format: .../ResourceType/uuid
        $parts = explode('/', rtrim($location, '/'));
        $last = end($parts);
        return !empty($last) ? $last : null;
    }
}
