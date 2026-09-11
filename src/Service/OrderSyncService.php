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
        //    OpenEMR sync uses a deterministic UUID as the OpenELIS logical id
        //    (see below). If the patient was already synced with that UUID we
        //    reuse it; anything else found by identifier (e.g. a HAPI-assigned
        //    sequential id like Patient/104) is ignored because OpenELIS's
        //    EMR-LIS importer cannot downstream a non-UUID logical id.
        if ($pubpid !== '') {
            $existing = $this->client->findPatientByIdentifier($pubpid);
            if ($existing && !empty($existing['id']) && self::isUuid($existing['id'])) {
                return 'Patient/' . $existing['id'];
            }
        }

        $existingByPid = $this->client->findPatientByIdentifier($pidStr);
        if ($existingByPid && !empty($existingByPid['id']) && self::isUuid($existingByPid['id'])) {
            return 'Patient/' . $existingByPid['id'];
        }

        // 2. Build the Patient and force a deterministic UUID logical id via
        //    PUT. OpenELIS's TaskInterpreterImpl/DBOrderPersister parse the
        //    remote Patient id with UUID.fromString(), so HAPI sequential ids
        //    (from POST) cannot be imported. A deterministic UUID keeps the
        //    id stable across resends (idempotent PUT).
        $patientUuid = self::uuidV5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'openemr-patient-' . $patientId);
        $fhirPatient = PatientMapper::toFhirPatient($patientData);
        $fhirPatient['id'] = $patientUuid;

        // Include the OpenELIS pat_guid identifier on every sync. The EMR-LIS
        // importer (TaskInterpreterImpl) maps this to MessagePatient.guid, and
        // DBOrderPersister dedups patients via getPatientForGuid() — a stable
        // GUID means re-imports UPDATE the existing OpenELIS patient instead of
        // creating a duplicate row (the externalId-based fallback is unreliable
        // because OpenELIS's subscriber adds it asynchronously, so the first
        // poll often sees a patient without it).
        $fhirPatient['identifier'][] = [
            'system' => 'http://openelis-global.org/pat_guid',
            'value' => $patientUuid,
        ];

        // 3. Create (PUT) the patient in OpenELIS under the deterministic UUID.
        $created = $this->client->createResource($fhirPatient, $patientUuid);

        $patientIdResolved = $created['id'] ?? $patientUuid;

        if (empty($patientIdResolved)) {
            throw new \RuntimeException(
                "Patient was accepted by OpenELIS (HTTP 201) but the server-assigned ID could not be retrieved."
            );
        }

        return 'Patient/' . $patientIdResolved;
    }

    /**
     * Deterministic RFC 4122 UUIDv5 from a name in a namespace (PHP-compatible,
     * no external uuid extension required). Used so OpenELIS FHIR resources get
     * stable, importer-compatible UUID logical ids.
     */
    private static function uuidV5(string $namespaceUuid, string $name): string
    {
        // Convert namespace uuid (hex) to binary
        $nhex = str_replace('-', '', $namespaceUuid);
        $nbytes = '';
        for ($i = 0; $i < 16; $i++) {
            $nbytes .= chr((int)hexdec(substr($nhex, $i * 2, 2)));
        }

        // Hash the namespace + name with sha1
        $hash = sha1($nbytes . $name, true);

        // Set version and variant bits
        $hash[6] = chr((ord($hash[6]) & 0x0f) | 0x50); // version 5
        $hash[8] = chr((ord($hash[8]) & 0x3f) | 0x80); // variant RFC 4122

        // Format as uuid
        return sprintf(
            '%08s-%04s-%04s-%04s-%012s',
            bin2hex(substr($hash, 0, 4)),
            bin2hex(substr($hash, 4, 2)),
            bin2hex(substr($hash, 6, 2)),
            bin2hex(substr($hash, 8, 2)),
            bin2hex(substr($hash, 10, 6))
        );
    }

    /**
     * True when the given FHIR logical id is a UUID (OpenELIS importer
     * requirement), false for HAPI sequential ids like "104".
     */
    private static function isUuid(string $id): bool
    {
        return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id);
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
     * 6. Create Specimen + ServiceRequest resources in the OpenELIS FHIR store
     * 7. Update procedure_order with sync status
     * 8. Publish a Task (status=requested) wrapping every ServiceRequest —
     *    the EMR-LIS container OpenELIS polls to import the order.
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
            $serviceRequestRefs = []; // "ServiceRequest/<uuid>" refs to attach to the EMR-LIS Task

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

                $seq = (int)($code['procedure_order_seq'] ?? 0);

                // 1. Create Specimen in OpenELIS
                $specimen = OrderMapper::toFhirSpecimen($patientRef, $procedureCode, (int)$provider['ppid']);
                try {
                    $createdSpecimen = $client->createResource($specimen);
                    $specimenId = $createdSpecimen['id'] ?? null;
                    if (empty($specimenId)) {
                        $latestSp = $client->fetchLatestSpecimen($patientRef);
                        $specimenId = $latestSp['id'] ?? null;
                    }
                } catch (\Exception $e) {
                    error_log("OpenELIS sync: Specimen creation notice for '$procedureCode': " . $e->getMessage());
                    $specimenId = null;
                }

                $specimenRef = !empty($specimenId) ? 'Specimen/' . $specimenId : null;
                if ($specimenRef) {
                    $openelisIds[] = $specimenRef;
                }

                // 2. Build and create ServiceRequest in OpenELIS
                $serviceRequest = OrderMapper::toFhirServiceRequest(
                    $order,
                    $code,
                    $patientRef,
                    $practitionerRef,
                    (int)$provider['ppid']
                );
                if ($specimenRef) {
                    $serviceRequest['specimen'] = [['reference' => $specimenRef]];
                }

                $createdSr = $client->createResource($serviceRequest);
                $srId = $createdSr['id'] ?? null;
                if (empty($srId)) {
                    $latestSr = $client->fetchLatestServiceRequest($patientRef);
                    $srId = $latestSr['id'] ?? null;
                }

                if (!empty($srId)) {
                    $srRef = 'ServiceRequest/' . $srId;
                    $openelisIds[] = $srRef;
                    $serviceRequestRefs[] = $srRef;

                    sqlStatement(
                        "UPDATE procedure_order_code
                         SET mod_openelis_service_request_id = ?,
                             mod_openelis_results_status = 'pending',
                             mod_openelis_results_at = NULL
                         WHERE procedure_order_id = ? AND procedure_order_seq = ?",
                        [$srRef, $procedureOrderId, $seq]
                    );
                }
            }

            // 8. Publish the EMR-LIS Task container for this order. OpenELIS
            // surfaces incoming lab orders by polling the remote source for
            // Task resources (status=requested, owner matching
            // org.openelisglobal.remote.source.identifier), so without the
            // Task the ServiceRequests above would sit in the FHIR store but
            // never appear in the Electronic Orders queue.
            $taskRef = null;
            if (!empty($serviceRequestRefs)) {
                try {
                    $task = OrderMapper::toFhirTask(
                        $serviceRequestRefs,
                        $patientRef,
                        $practitionerRef,
                        $order['date_ordered'] ?? null
                    );
                    $createdTask = $client->createResource($task);
                    $taskId = $createdTask['id'] ?? null;
                    if (!empty($taskId)) {
                        $taskRef = 'Task/' . $taskId;
                        $openelisIds[] = $taskRef;
                    }
                } catch (\Exception $e) {
                    error_log("OpenELIS sync: Task creation notice for order #$procedureOrderId: " . $e->getMessage());
                    $taskRef = null;
                }
            }

            if (empty($openelisIds)) {
                return [
                    'success' => false,
                    'message' => xl('None of the tests could be sent to OpenELIS. Please check code mappings and OpenELIS connection.'),
                    'openelis_ids' => [],
                ];
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
                     mod_openelis_sync_status = ?,
                     mod_openelis_order_id = ?,
                     mod_openelis_patient_ref = ?,
                     mod_openelis_task_id = ?
                 WHERE procedure_order_id = ?",
                [$taskRef ? 'sent' : 'error', $orderIdToStore, $patientRef, $taskRef, $procedureOrderId]
            );

            if (!$taskRef) {
                return [
                    'success' => false,
                    'message' => xl('ServiceRequests were created in OpenELIS but the Task could not be published. OpenELIS requires the Task to import the order into Electronic Orders.'),
                    'openelis_ids' => $openelisIds,
                ];
            }

            $message = xl('Order sent to OpenELIS successfully') . " ($taskRef)";
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

            $detail = OpenElisApiException::parseOutcomeDetail($e->getResponseBody());
            $errorMsg = xl('Error communicating with OpenELIS') . ' (HTTP ' . $e->getHttpStatus() . ')';
            if ($detail !== '') {
                $errorMsg .= ': ' . $detail;
            }

            return [
                'success' => false,
                'message' => $errorMsg,
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
