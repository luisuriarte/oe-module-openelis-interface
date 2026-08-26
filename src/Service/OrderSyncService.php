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

        // Search existing patient in OpenELIS
        $existing = $this->client->findPatientByIdentifier($patientData['pubpid'] ?? '');
        if ($existing && !empty($existing['id'])) {
            return 'Patient/' . $existing['id'];
        }

        // Create new patient in OpenELIS
        $fhirPatient = PatientMapper::toFhirPatient($patientData);
        $created = $this->client->createResource($fhirPatient);

        if (empty($created['id'])) {
            throw new \RuntimeException("Failed to create patient in OpenELIS: no ID returned");
        }

        return 'Patient/' . $created['id'];
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

        // Search existing practitioner in OpenELIS
        $existing = $this->client->findPractitioner(
            $userData['npi'] ?? '',
            $userData['lname'] ?? '',
            $userData['fname'] ?? ''
        );
        if ($existing && !empty($existing['id'])) {
            return 'Practitioner/' . $existing['id'];
        }

        // Create new practitioner in OpenELIS
        $fhirPractitioner = PractitionerMapper::toFhirPractitioner($userData);
        $created = $this->client->createResource($fhirPractitioner);

        if (empty($created['id'])) {
            throw new \RuntimeException("Failed to create practitioner in OpenELIS: no ID returned");
        }

        return 'Practitioner/' . $created['id'];
    }

    /**
     * Send a procedure_order to OpenELIS as a FHIR Transaction Bundle.
     *
     * Flow:
     * 1. Load procedure_order + codes + provider
     * 2. Validate provider is active and configured
     * 3. Sync patient to OpenELIS
     * 4. Sync practitioner to OpenELIS
     * 5. Build ServiceRequest + Specimen per test (skipping codes without LOINC)
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

            foreach ($codes as $code) {
                $procedureCode = $code['procedure_code'] ?? '';
                $loincCode = CodeMappingService::resolveLoincCode($procedureCode);

                if (empty($loincCode)) {
                    $skippedCodes[] = $code['procedure_name'] ?? $procedureCode;
                    error_log(
                        "OpenELIS sync: skipping test '$procedureCode' ("
                        . ($code['procedure_name'] ?? '') . ") — no LOINC code mapped"
                    );
                    continue;
                }

                // ServiceRequest
                $serviceRequest = OrderMapper::toFhirServiceRequest(
                    $order,
                    $code,
                    $patientRef,
                    $practitionerRef
                );
                $entries[] = [
                    'resource' => $serviceRequest,
                    'fullUrl' => 'urn:uuid:' . uniqid('sr-', true),
                ];

                // Specimen
                $specimen = OrderMapper::toFhirSpecimen($patientRef, $procedureCode);
                $entries[] = [
                    'resource' => $specimen,
                    'fullUrl' => 'urn:uuid:' . uniqid('sp-', true),
                ];
            }

            if (empty($entries)) {
                return [
                    'success' => false,
                    'message' => xl('None of the tests have LOINC codes mapped. Please configure code mappings first.'),
                    'openelis_ids' => [],
                ];
            }

            // 8. Build and send Transaction Bundle
            $bundle = OrderMapper::buildTransactionBundle($entries);
            $response = $client->createBundle($bundle);

            // 9. Extract created resource IDs from response
            if (!empty($response['entry'])) {
                foreach ($response['entry'] as $entry) {
                    $resourceType = $entry['response']['location'] ?? '';
                    if ($resourceType) {
                        $openelisIds[] = $resourceType;
                    }
                }
            }

            // 10. Mark order as synced
            $firstId = $openelisIds[0] ?? '';
            // external_id is varchar(20) — store a short reference
            $externalRef = substr(str_replace('/', '-', $firstId), 0, 20);

            sqlStatement(
                "UPDATE procedure_order
                 SET date_transmitted = NOW(),
                     mod_openelis_sync_status = 'sent',
                     control_id = ?
                 WHERE procedure_order_id = ?",
                [$externalRef, $procedureOrderId]
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
