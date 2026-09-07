<?php

namespace OpenEMR\Modules\OpenElis\Service;

use OpenEMR\Modules\OpenElis\Client\OpenElisApiClient;
use OpenEMR\Modules\OpenElis\Mappers\ResultMapper;

/**
 * Result reception: pulls DiagnosticReports from OpenELIS for an order that
 * was sent, verifies the patient identity (OpenELIS nationalId must equal
 * OpenEMR patient_data.pubpid), and stores the values in OpenEMR's native
 * lab tables (procedure_report + procedure_result) so they show up in the
 * standard OpenEMR lab-results UI.
 *
 * Correlation: each sent test line stores its "ServiceRequest/<uuid>" ref
 * (procedure_order_code.mod_openelis_service_request_id); we search
 * DiagnosticReport?based-on=<ref> and write one procedure_report per report
 * plus one procedure_result per Observation.
 *
 * Idempotency: a test line is marked mod_openelis_results_status='downloaded'
 * once its reports have been stored, so re-runs only fetch lines that have no
 * results yet. Amendments/corrections of already-downloaded results are out of
 * scope for this iteration.
 */
class ResultSyncService
{
    private OpenElisApiClient $client;

    public function __construct(OpenElisApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Fetch and import results for a single order.
     *
     * @param int $procedureOrderId  procedure_order.procedure_order_id
     * @return array ['success' => bool, 'message' => string, 'stats' => [...]]
     */
    public function syncOrderResults(int $procedureOrderId): array
    {
        $order = sqlQuery(
            "SELECT * FROM procedure_order WHERE procedure_order_id = ?",
            [$procedureOrderId]
        );

        if (empty($order)) {
            return ['success' => false, 'message' => xl('Order not found'), 'stats' => []];
        }

        if (($order['mod_openelis_sync_status'] ?? '') !== 'sent') {
            return [
                'success' => false,
                'message' => xl('The order was not sent to OpenELIS'),
                'stats' => [],
            ];
        }

        $provider = sqlQuery(
            "SELECT * FROM procedure_providers WHERE ppid = ? AND active = 1",
            [$order['lab_id'] ?? 0]
        );
        if (empty($provider)) {
            return [
                'success' => false,
                'message' => xl('No active lab provider configured for this order'),
                'stats' => [],
            ];
        }
        if (($provider['protocol'] ?? '') !== 'WS') {
            return [
                'success' => false,
                'message' => xl('Lab provider protocol must be set to Web Services (WS) to receive results from OpenELIS'),
                'stats' => [],
            ];
        }

        // Patient identity: the DiagnosticReport.subject (Patient/<uuid>) must
        // correspond to patient_data.pubpid (OpenELIS nationalId). We verify
        // the stored ref from the send flow; if missing, look up by nationalId.
        $patientData = sqlQuery(
            "SELECT pid, pubpid FROM patient_data WHERE pid = ?",
            [$order['patient_id']]
        );
        if (empty($patientData)) {
            return ['success' => false, 'message' => xl('Patient not found'), 'stats' => []];
        }

        try {
            $verifiedPatientRef = $this->verifyPatientRef($order, $patientData['pubpid'] ?? '');
        } catch (\RuntimeException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'stats' => [],
            ];
        }

        $codes = [];
        $rsCodes = sqlStatement(
            "SELECT procedure_order_seq, procedure_code, procedure_name,
                    mod_openelis_service_request_id, mod_openelis_results_status
             FROM procedure_order_code
             WHERE procedure_order_id = ? AND do_not_send = 0
               AND mod_openelis_service_request_id IS NOT NULL
               AND (mod_openelis_results_status IS NULL OR mod_openelis_results_status != 'downloaded')
             ORDER BY procedure_order_seq",
            [$procedureOrderId]
        );
        while ($row = sqlFetchArray($rsCodes)) {
            $codes[] = $row;
        }

        if (empty($codes)) {
            return [
                'success' => true,
                'message' => xl('No pending results for this order'),
                'stats' => ['reports' => 0, 'results' => 0, 'tests' => 0],
            ];
        }

        $stats = ['reports' => 0, 'results' => 0, 'tests' => 0];
        $errors = [];

        foreach ($codes as $code) {
            $seq = (int)$code['procedure_order_seq'];
            $serviceRequestRef = (string)$code['mod_openelis_service_request_id'];

            try {
                $reports = $this->client->findDiagnosticReportsByServiceRequest($serviceRequestRef);
                $importedReports = 0;

                foreach ($reports as $report) {
                    // Never import results that reference a different patient.
                    if (($report['subject']['reference'] ?? '') !== $verifiedPatientRef) {
                        error_log(
                            "OpenELIS results: report subject "
                            . ($report['subject']['reference'] ?? '?')
                            . " does not match order patient $verifiedPatientRef — skipped"
                        );
                        continue;
                    }

                    $observations = $this->client->fetchReportObservations($report);
                    if (empty($observations)) {
                        continue;
                    }

                    $mapped = ResultMapper::toOpenEmr($report, $observations);
                    if (empty($mapped['results'])) {
                        continue;
                    }

                    $reportId = $this->storeReport($order, $seq, $mapped['report']);
                    foreach ($mapped['results'] as $row) {
                        $this->storeResult($reportId, $row);
                    }
                    $stats['reports']++;
                    $stats['results'] += count($mapped['results']);
                    $importedReports++;
                }

                if ($importedReports > 0) {
                    sqlStatement(
                        "UPDATE procedure_order_code
                         SET mod_openelis_results_status = 'downloaded', mod_openelis_results_at = NOW()
                         WHERE procedure_order_id = ? AND procedure_order_seq = ?",
                        [$procedureOrderId, $seq]
                    );
                    $stats['tests']++;
                }
            } catch (\Exception $e) {
                $errors[] = $code['procedure_name'] ?? $seq;
                error_log("OpenELIS results sync failed for order #$procedureOrderId test #$seq: " . $e->getMessage());
                sqlStatement(
                    "UPDATE procedure_order_code
                     SET mod_openelis_results_status = 'error', mod_openelis_results_at = NOW()
                     WHERE procedure_order_id = ? AND procedure_order_seq = ?",
                    [$procedureOrderId, $seq]
                );
            }
        }

        $message = xl('Order') . ' #' . $procedureOrderId . ': '
            . $stats['results'] . ' ' . strtolower(xl('results')) . ' ('
            . $stats['reports'] . ' ' . strtolower(xl('reports')) . ')';
        if ($errors) {
            $message .= '. ' . xl('Errors') . ': ' . implode(', ', $errors);
        }

        return [
            'success' => true,
            'message' => $message,
            'stats' => $stats,
            'errors' => $errors,
        ];
    }

    /**
     * Iterate every OpenELIS order with pending results and import them.
     * A separate client is built per order because orders may belong to
     * different lab providers.
     *
     * @return array ['success' => bool, 'message' => string, 'stats' => [...]]
     */
    public function syncAllResults(): array
    {
        $orders = [];
        $rs = sqlStatement(
            "SELECT po.procedure_order_id,
                    pp.remote_host, pp.login, pp.password
             FROM procedure_order po
             INNER JOIN procedure_providers pp ON po.lab_id = pp.ppid
             WHERE po.activity = 1
               AND po.mod_openelis_sync_status = 'sent'
               AND pp.protocol = 'WS'
               AND pp.mod_openelis_catalog_login IS NOT NULL AND pp.mod_openelis_catalog_login != ''
               AND EXISTS (
                    SELECT 1 FROM procedure_order_code poc
                    WHERE poc.procedure_order_id = po.procedure_order_id
                      AND poc.do_not_send = 0
                      AND poc.mod_openelis_service_request_id IS NOT NULL
                      AND (poc.mod_openelis_results_status IS NULL
                           OR poc.mod_openelis_results_status != 'downloaded')
               )
             ORDER BY po.date_ordered"
        );
        while ($row = sqlFetchArray($rs)) {
            $orders[] = $row;
        }

        $stats = ['reports' => 0, 'results' => 0, 'tests' => 0];
        $failures = 0;
        $errors = [];

        foreach ($orders as $row) {
            $procedureOrderId = (int)$row['procedure_order_id'];
            if (empty($row['remote_host'])) {
                $failures++;
                $errors[] = '#' . $procedureOrderId . ' ' . xl('No active lab provider configured for this order');
                continue;
            }
            try {
                $client = new OpenElisApiClient($row['remote_host'], $row['login'], $row['password']);
                $out = (new self($client))->syncOrderResults($procedureOrderId);
            } catch (\Exception $e) {
                $failures++;
                $errors[] = '#' . $procedureOrderId . ' ' . $e->getMessage();
                continue;
            }
            if (!empty($out['stats'])) {
                $stats['reports'] += $out['stats']['reports'];
                $stats['results'] += $out['stats']['results'];
                $stats['tests'] += $out['stats']['tests'];
            }
            if (empty($out['success'])) {
                $failures++;
                $errors[] = '#' . $procedureOrderId . ' ' . $out['message'];
            }
        }

        $message = $stats['results'] . ' ' . strtolower(xl('results')) . ' / '
            . $stats['tests'] . ' ' . strtolower(xl('tests')) . ' / '
            . $stats['reports'] . ' ' . strtolower(xl('reports'));
        if ($failures > 0) {
            $message .= ' — ' . $failures . ' ' . strtolower(xl('orders')) . ' ' . xl('failed');
        }

        return [
            'success' => $failures === 0,
            'message' => $message,
            'stats' => $stats,
            'failures' => $failures,
        ];
    }

    private function verifyPatientRef(array $order, string $pubpid): string
    {
        $stored = (string)($order['mod_openelis_patient_ref'] ?? '');

        if ($stored !== '') {
            $patientId = self::extractId($stored);
            $fhirPatient = $this->client->fetchResource('Patient', $patientId);
            if ($fhirPatient === null) {
                throw new \RuntimeException(xl('OpenELIS patient not found') . " ($stored)");
            }
            $nationalId = self::nationalIdOf($fhirPatient);
            if ($nationalId !== $pubpid) {
                throw new \RuntimeException(
                    xl('Patient identity mismatch')
                    . ": OpenELIS nationalId=\"$nationalId\" vs pubpid=\"$pubpid\""
                );
            }
            return $stored;
        }

        // No stored ref (order sent before this column existed): find by pubpid.
        $found = $this->client->findPatientByIdentifier($pubpid);
        if ($found === null || empty($found['id'])) {
            throw new \RuntimeException(xl('Patient not found in OpenELIS'));
        }
        return 'Patient/' . $found['id'];
    }

    /**
     * Extract the logical id from a "ResourceType/<id>" style ref.
     */
    private static function extractId(string $ref): string
    {
        if (preg_match('#^[A-Za-z]+/(.+)$#', $ref, $m)) {
            return $m[1];
        }
        return $ref;
    }

    /**
     * Read the national_id identifier value from a FHIR Patient resource.
     */
    private static function nationalIdOf(array $fhirPatient): string
    {
        foreach (($fhirPatient['identifier'] ?? []) as $identifier) {
            if (($identifier['system'] ?? '') === 'http://openelis-global.org/pat_nationalId') {
                return (string)($identifier['value'] ?? '');
            }
        }
        return '';
    }

    private function storeReport(array $order, int $seq, array $reportRow): int
    {
        return (int)sqlInsert(
            "INSERT INTO procedure_report
                (procedure_order_id, procedure_order_seq, date_report, source,
                 specimen_num, report_status, report_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $order['procedure_order_id'],
                $seq,
                $reportRow['date_report'],
                // source = procedure_providers.ppid of the delivering lab (not a users.id)
                0,
                $reportRow['specimen_num'],
                $reportRow['report_status'],
                $reportRow['report_notes'],
            ]
        );
    }

    private function storeResult(int $reportId, array $row): void
    {
        sqlInsert(
            "INSERT INTO procedure_result
                (procedure_report_id, result_data_type, result_code, result_text,
                 date, units, result, range, abnormal, comments, result_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $reportId,
                $row['result_data_type'],
                $row['result_code'],
                $row['result_text'],
                $row['date'],
                $row['units'],
                $row['result'],
                $row['range'],
                $row['abnormal'],
                $row['comments'],
                $row['result_status'],
            ]
        );
    }
}