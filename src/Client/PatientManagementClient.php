<?php

namespace OpenEMR\Modules\OpenElis\Client;

use OpenEMR\Modules\OpenElis\Mappers\PatientManagementMapper;

/**
 * HTTP client for the OpenELIS Global 2 native REST endpoint:
 *   POST /OpenELIS-Global/rest/PatientManagement
 *
 * This client pre-creates or updates patients in OpenELIS's relational
 * database before electronic orders are dispatched.
 *
 * OpenELIS Java reference:
 *   org.openelisglobal.patient.action.bean.PatientManagementInfo
 *   SamplePatientEntryController.setupForm
 */
class PatientManagementClient
{
    private const DEFAULT_ORIGIN = 'https://127.0.0.1:8443';
    private const DEFAULT_HOST_HEADER = 'elis.origen.ar';
    private const PRIMARY_PATH = '/OpenELIS-Global/rest/PatientManagement';
    private const FALLBACK_PATH = '/rest/PatientManagement';

    private string $origin;
    private array $credentialsList = [];
    private string $hostHeader;

    /**
     * @param string|null $remoteHost       Origin or provider remote_host
     * @param array       $credentialsList  Array of ['login' => ..., 'password' => ...] pairs to try
     */
    public function __construct(?string $remoteHost, array $credentialsList)
    {
        $this->origin = self::resolveOrigin($remoteHost);
        $this->credentialsList = $credentialsList;
        $this->hostHeader = self::resolveHostHeader($remoteHost);
    }

    /**
     * Create client instance from OpenEMR procedure_providers row.
     *
     * Collects all candidate credentials (catalog login, provider login)
     * so that if one user lacks permissions (HTTP 401), the client falls back to the next.
     *
     * @param array $provider  Row from procedure_providers
     * @return self
     */
    public static function fromProvider(array $provider): self
    {
        $candidates = [];

        $catalogLogin = trim((string)($provider['mod_openelis_catalog_login'] ?? ''));
        $catalogPass = (string)($provider['mod_openelis_catalog_password'] ?? '');
        if ($catalogLogin !== '') {
            $candidates[] = ['login' => $catalogLogin, 'password' => $catalogPass];
        }

        $provLogin = trim((string)($provider['login'] ?? ''));
        $provPass = (string)($provider['password'] ?? '');
        if ($provLogin !== '' && $provLogin !== $catalogLogin) {
            $candidates[] = ['login' => $provLogin, 'password' => $provPass];
        }

        if (empty($candidates)) {
            throw new \RuntimeException(
                "No OpenELIS credentials found for lab provider (ppid="
                . ($provider['ppid'] ?? '?') . "). "
                . "Set mod_openelis_catalog_login or login/password in procedure_providers."
            );
        }

        $remoteHost = (string)($provider['remote_host'] ?? '');

        return new self($remoteHost, $candidates);
    }

    /**
     * Pre-create or update a patient in OpenELIS via REST PatientManagement.
     *
     * KNOWN LIMITATION:
     *   OpenELIS does not currently provide a standalone REST endpoint to
     *   search patientPK before accession. Therefore, patientPK is sent empty
     *   ("") for new patient creation. If a patient search REST API is added in
     *   the future, patientPK can be passed to perform an update instead.
     *
     * @param array  $patientData  OpenEMR patient_data row
     * @param string $patientPk    Existing OpenELIS patient PK if known
     * @return array Decoded response payload from OpenELIS
     * @throws OpenElisApiException If the request fails or returns HTTP >= 400
     */
    public function syncPatient(array $patientData, string $patientPk = ''): array
    {
        $payload = PatientManagementMapper::toPatientManagementInfo($patientData, $patientPk);

        error_log(
            "OpenELIS PatientManagement: sending patient sync for OpenEMR pid="
            . ($patientData['pid'] ?? '?') . " (subjectNumber={$payload['subjectNumber']}, nationalId={$payload['nationalId']})"
        );

        return $this->postPatientManagement($payload);
    }

    /**
     * Send POST request to /rest/PatientManagement with fallback path support.
     *
     * @param array $payload
     * @return array
     * @throws OpenElisApiException
     */
    private function postPatientManagement(array $payload): array
    {
        $paths = [self::PRIMARY_PATH, self::FALLBACK_PATH];
        $lastException = null;

        foreach ($this->credentialsList as $cred) {
            $user = $cred['login'];
            $pass = $cred['password'];

            foreach ($paths as $path) {
                $url = $this->origin . $path;
                try {
                    return $this->executeRequest($url, $payload, $user, $pass);
                } catch (OpenElisApiException $e) {
                    // If 401, break to try the next user credentials
                    if ($e->getHttpStatus() === 401) {
                        error_log("OpenELIS PatientManagement: user '$user' got 401, trying next candidate...");
                        $lastException = $e;
                        break;
                    }
                    // If 404, try fallback path
                    if ($e->getHttpStatus() === 404 && $path === self::PRIMARY_PATH) {
                        error_log("OpenELIS PatientManagement: primary path 404, trying fallback {$this->origin}" . self::FALLBACK_PATH);
                        $lastException = $e;
                        continue;
                    }
                    throw $e;
                }
            }
        }

        throw $lastException ?? new \RuntimeException("OpenELIS PatientManagement failed on all endpoints and credentials.");
    }

    /**
     * Execute HTTP request with cURL supporting Basic Auth, session cookies and CSRF tokens.
     *
     * @param string $url
     * @param array  $payload
     * @param string $login
     * @param string $password
     * @return array
     * @throws OpenElisApiException
     */
    private function executeRequest(string $url, array $payload, string $login, string $password): array
    {
        $ch = curl_init();

        $headers = [
            'Host: ' . $this->hostHeader,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_USERPWD        => $login . ':' . $password,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIEJAR      => '', // In-memory cookie jar for JSESSIONID
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $responseBody = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            $msg = "cURL error communicating with OpenELIS PatientManagement ($url): $curlError";
            error_log("OpenELIS PatientManagement error: $msg");
            throw new \RuntimeException($msg);
        }

        if ($statusCode >= 400) {
            $errDetail = "HTTP $statusCode from $url: " . substr((string)$responseBody, 0, 500);
            error_log("OpenELIS PatientManagement rejected request: $errDetail");
            throw new OpenElisApiException($statusCode, (string)$responseBody);
        }

        error_log("OpenELIS PatientManagement: successfully registered patient (HTTP $statusCode)");

        $decoded = json_decode((string)$responseBody, true);
        return is_array($decoded) ? $decoded : ['status' => $statusCode, 'raw' => $responseBody];
    }

    /**
     * Resolve the Webapp HTTP origin.
     *
     * If remoteHost points to the FHIR store ports (8080/8081/8444),
     * points instead to the default OpenELIS Webapp origin (https://127.0.0.1:8443).
     */
    private static function resolveOrigin(?string $remoteHost): string
    {
        $remoteHost = trim((string)$remoteHost);
        if (empty($remoteHost)) {
            return self::DEFAULT_ORIGIN;
        }

        if (filter_var($remoteHost, FILTER_VALIDATE_URL)) {
            $p = parse_url($remoteHost);
            $port = isset($p['port']) ? (int)$p['port'] : null;
            // If port belongs to external-fhir-api (8080, 8081, 8444), webapp is on 8443
            if (in_array($port, [8080, 8081, 8444], true)) {
                return self::DEFAULT_ORIGIN;
            }

            $scheme = $p['scheme'] ?? 'https';
            $host = $p['host'] ?? '127.0.0.1';
            $portStr = $port ? ':' . $port : '';
            return $scheme . '://' . $host . $portStr;
        }

        return self::DEFAULT_ORIGIN;
    }

    /**
     * Resolve Host header for Docker loopback routing.
     */
    private static function resolveHostHeader(?string $remoteHost): string
    {
        $remoteHost = trim((string)$remoteHost);
        if (filter_var($remoteHost, FILTER_VALIDATE_URL)) {
            $host = parse_url($remoteHost, PHP_URL_HOST);
            if ($host !== false && $host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
                return $host;
            }
        }
        return self::DEFAULT_HOST_HEADER;
    }
}
