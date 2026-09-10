<?php

namespace OpenEMR\Modules\OpenElis\Service;

use OpenEMR\Modules\OpenElis\Client\OpenElisApiClient;
use OpenEMR\Modules\OpenElis\Client\OpenElisApiException;
use OpenEMR\Modules\OpenElis\CodeMappingService;
use OpenEMR\Modules\OpenElis\Mappers\OrderMapper;
use OpenEMR\Modules\OpenElis\Mappers\PatientMapper;
use OpenEMR\Modules\OpenElis\Mappers\PractitionerMapper;

class OrderSyncService
{
    private OpenElisApiClient $client;

    public function __construct(OpenElisApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Sync an OpenEMR patient to OpenELIS.
     *
     * Looks up the patient by pubpid. If not found, creates a new Patient
     * in OpenELIS. Returns the OpenELIS Patient resource ID.
     *
     * TODO: Cache the openelis_patient_id in patient_data or a dedicated
     * table to avoid re-looking-up on every sync.
     *
     * @param int $patientId  OpenEMR patient_data.pid
     * @return string         OpenELIS Patient resource ID
     * @throws \RuntimeException
     */
    public function syncPatientToOpenElis(int $patientId): string
    {
        $patientData = sqlQuery(
            "SELECT pid, pubpid, fname, lname, DOB, sex, street, city, state, postal_code, phone_cell
             FROM patient_data WHERE pid = ?",
            [$patientId]
        );

        if (empty($patientData)) {
            throw new \RuntimeException("Patient not found: pid=$patientId");
        }

        $pubpid = trim((string)($patientData['pubpid'] ?? ''));
        $pidStr = (string)$patientId;

        // 1. Search existing patient in OpenELIS
        if ($pubpid !== '') {
            $existing = $this->client->findPatientByIdentifier($pubpid);
            if ($existing && !empty($existing['id'])) {
                return 'Patient/' . $existing['id'];
            }
        }

        $existingByPid = $this->client->findPatientByIdentifier($pidStr);
        if ($existingByPid && !empty($existingByPid['id'])) {
            return 'Patient/' . $existingByPid['id'];
        }

        $existingByName = $this->client->findPatientByName(
            $patientData['lname'] ?? '',
            $patientData['fname'] ?? ''
        );
        if ($existingByName && !empty($existingByName['id'])) {
            return 'Patient/' . $existingByName['id'];
        }

        // 2. Create new patient in OpenELIS
        $fhirPatient = PatientMapper::toFhirPatient($patientData);
        $created = $this->client->createResource($fhirPatient);

        $patientIdResolved = $created['id'] ?? null;

        // 3. If OpenELIS accepted the patient (HTTP 201) but returned no Location/body,
        // re-query to fetch the server-assigned ID.
        if (empty($patientIdResolved) && $pubpid !== '') {
            $found = $this->client->findPatientByIdentifier($pubpid);
            if ($found && !empty($found['id'])) {
                $patientIdResolved = $found['id'];
            }
        }

        if (empty($patientIdResolved)) {
            $foundByPid = $this->client->findPatientByIdentifier($pidStr);
            if ($foundByPid && !empty($foundByPid['id'])) {
                $patientIdResolved = $foundByPid['id'];
            }
        }

        if (empty($patientIdResolved)) {
            $foundByName = $this->client->findPatientByName(
                $patientData['lname'] ?? '',
                $patientData['fname'] ?? ''
            );
            if ($foundByName && !empty($foundByName['id'])) {
                $patientIdResolved = $foundByName['id'];
            }
        }

        // 4. Ultimate fallback: fetch the most recently created patient in OpenELIS
        if (empty($patientIdResolved)) {
            $latest = $this->client->fetchLatestPatient();
            if ($latest && !empty($latest['id'])) {
                error_log("OpenELIS: resolved patient ID via fetchLatestPatient() -> {$latest['id']}");
                $patientIdResolved = $latest['id'];
            }
        }

        if (empty($patientIdResolved)) {
            throw new \RuntimeException(
                "Patient was accepted by OpenELIS (HTTP 201) but the server-assigned ID could not be retrieved."
            );
        }

        return 'Patient/' . $patientIdResolved;
    }

    /**
     * Find or create a Practitioner in OpenELIS.
     *
     * @param int $providerId  OpenEMR users.id
     * @return string          OpenELIS Practitioner resource reference
     * @throws \RuntimeException
     */
    public function syncPractitionerToOpenElis(int $providerId): string
    {
        $userData = sqlQuery(
            "SELECT id, fname, lname, npi FROM users WHERE id = ?",
            [$providerId]
        );

        if (empty($userData)) {
            throw new \RuntimeException("Provider not found: id=$providerId");
        }

        $npi = $userData['npi'] ?? '';
        $lname = $userData['lname'] ?? '';
        $fname = $userData['fname'] ?? '';

        // Search existing practitioner in OpenELIS
        $existing = $this->client->findPractitioner($npi, $lname, $fname);
        if ($existing && !empty($existing['id'])) {
            return 'Practitioner/' . $existing['id'];
        }

        // Create new practitioner in OpenELIS
        $fhirPractitioner = PractitionerMapper::toFhirPractitioner($userData);
        $created = $this->client->createResource($fhirPractitioner);

        $practitionerIdResolved = $created['id'] ?? null;
        if (empty($practitionerIdResolved)) {
            $found = $this->client->findPractitioner($npi, $lname, $fname);
            if ($found && !empty($found['id'])) {
                $practitionerIdResolved = $found['id'];
            }
        }

        if (empty($practitionerIdResolved)) {
            $latestPrac = $this->client->fetchLatestPractitioner();
            if ($latestPrac && !empty($latestPrac['id'])) {
                error_log("OpenELIS: resolved practitioner ID via fetchLatestPractitioner() -> {$latestPrac['id']}");
                $practitionerIdResolved = $latestPrac['id'];
            }
        }

        if (empty($practitionerIdResolved)) {
            throw new \RuntimeException("Failed to create practitioner in OpenELIS: no ID returned");
        }

        return 'Practitioner/' . $practitionerIdResolved;
    }

    /**
     * Send a procedure_order to OpenELIS as a FHIR Transaction Bundle.
     *
     * Flow:
     * 1. Load procedure_order + codes + provider
     * 2. Validate provider is active and configured
     * 3. Sync patient to OpenELIS
     * 4. Sync practitioner to OpenELIS
     * 5. Build ServiceRequest + Specimen per test (skipping unmapped codes)
     * 6. Send as FHIR Transaction Bundle
     * 7. Update procedure_order with sync status
     *
     * @param int $procedureOrderId  procedure_order.procedure_order_id
     * @return array  ['success' => bool, 'message' => string, 'openelis_ids' => array]
     */
    public function sendOrderToOpenElis(int $procedureOrderId): array
    {
        // 1. Load the order
        $order = sqlQuery(
            "SELECT * FROM procedure_order WHERE procedure_order_id = ?",
            [$procedureOrderId]
        );

        error_log("OpenELIS sendOrderToOpenElis() called for order #$procedureOrderId, lab_id=" . ($order['lab_id'] ?? '?'));

        if (empty($order)) {
            return ['success' => false, 'message' => xl('Order not found'), 'openelis_ids' => []];
        }

        // 2. Load order codes (tests)
        $codes = [];
        $rsCodes = sqlStatement(
            "SELECT * FROM procedure_order_code WHERE procedure_order_id = ? AND do_not_send = 0 ORDER BY procedure_order_seq",
            [$procedureOrderId]
        );
        while ($row = sqlFetchArray($rsCodes)) {
            $codes[] = $row;
        }

        if (empty($codes)) {
            return ['success' => false, 'message' => xl('No tests to send'), 'openelis_ids' => []];
        }

        // 3. Load provider
        $provider = sqlQuery(
            "SELECT * FROM procedure_providers WHERE ppid = ? AND active = 1",
            [$order['lab_id'] ?? 0]
        );

        if (empty($provider)) {
            return [
                'success' => false,
                'message' => xl('No active lab provider configured for this order'),
                'openelis_ids' => [],
            ];
        }

        // OpenELIS requires protocol = 'WS' (Web Services) to send orders.
        if (($provider['protocol'] ?? '') !== 'WS') {
            return [
                'success' => false,
                'message' => xl('Lab provider protocol must be set to Web Services (WS) to send orders via OpenELIS'),
                'openelis_ids' => [],
            ];
        }

        // 4. Create API client
        $client = new OpenElisApiClient(
            $provider['remote_host'],
            $provider['login'],
            $provider['password']
        );

        try {
            // 5. Sync patient
            $patientRef = $this->syncPatientToOpenElis($order['patient_id']);

            // 6. Sync practitioner
            $practitionerRef = $this->syncPractitionerToOpenElis($order['provider_id']);

            // 7. Build resources per test
            $entries = [];
            $skippedCodes = [];
            $openelisIds = [];
            $serviceRequestUris = []; // urn:uuid => procedure_order_seq, to correlate the response back to each test line

            foreach ($codes as $code) {
                $procedureCode = $code['procedure_code'] ?? '';
                $openelisTestId = CodeMappingService::resolveOpenElisTestId($procedureCode, (int)$provider['ppid']);

                // Skip only if there's no mapping at all — neither an explicit
                // openelis_test_id nor a procedure code that OpenELIS can resolve.
                // resolveWithFallback() returns the raw procedure_code as last resort,
                // but here we check whether a deliberate mapping exists.
                if (empty($openelisTestId)) {
                    $skippedCodes[] = $code['procedure_name'] ?? $procedureCode;
                    error_log(
                        "OpenELIS sync: skipping test '$procedureCode' ("
                        . ($code['procedure_name'] ?? '') . ") — no code mapping configured"
                    );
                    continue;
                }

                $srUri = 'urn:uuid:' . uniqid('sr-', true);
                $spUri = 'urn:uuid:' . uniqid('sp-', true);
                $serviceRequestUris[$srUri] = (int)($code['procedure_order_seq'] ?? 0);

                // ServiceRequest
                $serviceRequest = OrderMapper::toFhirServiceRequest(
                    $order,
                    $code,
                    $patientRef,
                    $practitionerRef,
                    (int)$provider['ppid']
                );
                // Link Specimen to ServiceRequest
                $serviceRequest['specimen'] = [['reference' => $spUri]];

                $entries[] = [
                    'resource' => $serviceRequest,
                    'fullUrl' => $srUri,
                ];

                // Specimen
                $specimen = OrderMapper::toFhirSpecimen($patientRef, $procedureCode, (int)$provider['ppid']);
                $entries[] = [
                    'resource' => $specimen,
                    'fullUrl' => $spUri,
                ];
            }

            if (empty($entries)) {
                return [
                    'success' => false,
                    'message' => xl('None of the tests have code mappings configured. Please configure code mappings first.'),
                    'openelis_ids' => [],
                ];
            }

            // 8. Build and send Transaction Bundle
            $bundle = OrderMapper::buildTransactionBundle($entries);
            $response = $client->createBundle($bundle);

            // 9. Extract created resource IDs from response
            // HAPI FHIR returns transaction entries with the created resource's location
            // e.g. "ServiceRequest/123/_history/1" or "http://host/fhir/ServiceRequest/123/_history/1".
            // We extract the clean "ResourceType/id" reference.
            if (!empty($response['entry'])) {
                foreach ($response['entry'] as $entry) {
                    $rawLoc = $entry['response']['location'] ?? '';
                    if (preg_match('~(?:^|/)((?:ServiceRequest|Specimen|Patient|Practitioner)/[^/_?#]+)~', $rawLoc, $matches)) {
                        $cleanRef = $matches[1];
                        $openelisIds[] = $cleanRef;

                        if (str_starts_with($cleanRef, 'ServiceRequest/')) {
                            $seq = $serviceRequestUris[$entry['fullUrl'] ?? ''] ?? null;
                            if ($seq !== null) {
                                sqlStatement(
                                    "UPDATE procedure_order_code
                                     SET mod_openelis_service_request_id = ?,
                                         mod_openelis_results_status = 'pending',
                                         mod_openelis_results_at = NULL
                                     WHERE procedure_order_id = ? AND procedure_order_seq = ?",
                                    [$cleanRef, $procedureOrderId, $seq]
                                );
                            }
                        }
                    }
                }
            }

            if (empty($openelisIds)) {
                throw new \RuntimeException(
                    "OpenELIS transaction bundle was processed but returned no valid resource locations."
                );
            }

            // 10. Mark order as synced
            // Store the primary ServiceRequest reference in mod_openelis_order_id.
            $firstSr = null;
            foreach ($openelisIds as $id) {
                if (str_starts_with($id, 'ServiceRequest/')) {
                    $firstSr = $id;
                    break;
                }
            }
            $orderIdToStore = $firstSr ?: ($openelisIds[0] ?? null);

            sqlStatement(
                "UPDATE procedure_order
                 SET date_transmitted = NOW(),
                     mod_openelis_sync_status = 'sent',
                     mod_openelis_order_id = ?,
                     mod_openelis_patient_ref = ?
                 WHERE procedure_order_id = ?",
                [$orderIdToStore, $patientRef, $procedureOrderId]
            );

            $message = xl('Order sent to OpenELIS successfully');
            if (!empty($skippedCodes)) {
                $message .= '. ' . xl('Skipped') . ': ' . implode(', ', $skippedCodes);
            }

            return [
                'success' => true,
                'message' => $message,
                'openelis_ids' => $openelisIds,
            ];
        } catch (OpenElisApiException $e) {
            error_log(
                "OpenELIS sync failed for order #$procedureOrderId: "
                . "HTTP {$e->getHttpStatus()} — {$e->getResponseBody()}"
            );

            sqlStatement(
                "UPDATE procedure_order SET mod_openelis_sync_status = 'error' WHERE procedure_order_id = ?",
                [$procedureOrderId]
            );

            return [
                'success' => false,
                'message' => xl('Error communicating with OpenELIS') . ' (HTTP ' . $e->getHttpStatus() . ')',
                'openelis_ids' => [],
            ];
        } catch (\Exception $e) {
            error_log("OpenELIS sync failed for order #$procedureOrderId: " . $e->getMessage());

            sqlStatement(
                "UPDATE procedure_order SET mod_openelis_sync_status = 'error' WHERE procedure_order_id = ?",
                [$procedureOrderId]
            );

            return [
                'success' => false,
                'message' => xl('Unexpected error') . ': ' . $e->getMessage(),
                'openelis_ids' => [],
            ];
        }
    }

    /**
     * Build a status summary for a pending order (for the pending orders page).
     *
     * @param int $procedureOrderId
     * @return array ['status' => string, 'detail' => string]
     */
    public function getOrderStatus(int $procedureOrderId): array
    {
        $order = sqlQuery(
            "SELECT mod_openelis_sync_status, date_transmitted, control_id
             FROM procedure_order WHERE procedure_order_id = ?",
            [$procedureOrderId]
        );

        if (empty($order)) {
            return ['status' => 'unknown', 'detail' => xl('Order not found')];
        }

        if (($order['mod_openelis_sync_status'] ?? '') === 'sent') {
            return [
                'status' => 'sent',
                'detail' => xl('Sent') . ' ' . $order['date_transmitted'],
            ];
        }

        if (($order['mod_openelis_sync_status'] ?? '') === 'error') {
            return ['status' => 'error', 'detail' => xl('Sync error — retry')];
        }

        if (!empty($order['date_transmitted'])) {
            return [
                'status' => 'sent_other',
                'detail' => xl('Transmitted') . ' ' . $order['date_transmitted'],
            ];
        }

        return ['status' => 'pending', 'detail' => xl('Pending')];
    }
}
