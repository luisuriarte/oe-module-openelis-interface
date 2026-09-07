<?php

namespace OpenEMR\Modules\OpenElis\Mappers;

/**
 * Maps OpenELIS FHIR R4 Diagnostics results (DiagnosticReport + the
 * Observations it references) into the row shape consumed by OpenEMR's
 * native lab tables (procedure_report / procedure_result).
 *
 * The OpenELIS "Development-Class" FHIR profile is thin, so the mapper is
 * defensive: it accepts several standard encodings of the value/range/flag
 * (valueQuantity, valueString, valueCodeableConcept, interpretation codings,
 * referenceRange text or low/high) and degrades gracefully.
 */
class ResultMapper
{
    /**
     * Status map for DiagnosticReport.status -> procedure_report.report_status.
     * 'received' = a result arrived but is not (yet) final; 'complete' = final.
     */
    private const REPORT_STATUS_MAP = [
        'final' => 'complete',
        'amended' => 'complete',
        'corrected' => 'complete',
        'preliminary' => 'received',
        'registered' => 'received',
        'partial' => 'received',
    ];

    /**
     * Status map for Observation.status -> procedure_result.result_status.
     */
    private const RESULT_STATUS_MAP = [
        'final' => 'final',
        'amended' => 'corrected',
        'corrected' => 'corrected',
        'preliminary' => 'preliminary',
        'cancelled' => 'cannot be done',
        'entered-in-error' => 'incomplete',
        'registered' => 'incomplete',
        'unknown' => 'incomplete',
    ];

    /**
     * Interpretation coding codes -> procedure_result.abnormal.
     */
    private const ABNORMAL_MAP = [
        'H' => 'high', 'HH' => 'high', 'high' => 'high',
        'L' => 'low', 'LL' => 'low', 'low' => 'low',
        'A' => 'yes', 'AA' => 'yes', 'VH' => 'high', 'VS' => 'low',
    ];

    /**
     * @param array $report        FHIR DiagnosticReport resource
     * @param array $observations  FHIR Observation resources (contents of report.result)
     * @return array [
     *   'report'  => [date_report, specimen_num, report_status, report_notes],
     *   'results' => [ [result_code, result_text, result_data_type, date,
     *                   units, result, range, abnormal, comments, result_status], ... ]
     * ]
     */
    public static function toOpenEmr(array $report, array $observations): array
    {
        return [
            'report' => self::mapReport($report),
            'results' => array_values(array_filter(array_map(
                fn($obs) => self::mapObservation($obs),
                $observations
            ), fn($r) => $r !== null)),
        ];
    }

    private static function mapReport(array $report): array
    {
        $status = strtolower((string)($report['status'] ?? ''));
        $reportStatus = self::REPORT_STATUS_MAP[$status] ?? 'received';

        $dateReport = null;
        foreach (['issued', 'effectiveDateTime'] as $key) {
            if (!empty($report[$key])) {
                $dateReport = self::fhirToOpenEmrDate($report[$key]);
                break;
            }
        }
        if ($dateReport === null && !empty($report['effectivePeriod']['start'])) {
            $dateReport = self::fhirToOpenEmrDate($report['effectivePeriod']['start']);
        }

        $specimen = '';
        if (isset($report['specimen'][0]['reference'])) {
            $specimen = $report['specimen'][0]['reference'];
        }

        $notes = null;
        if (!empty($report['conclusion'])) {
            $notes = is_array($report['conclusion']) ? implode("\n", $report['conclusion']) : $report['conclusion'];
        }

        return [
            'date_report' => $dateReport,
            'specimen_num' => (string)$specimen,
            'report_status' => $reportStatus,
            'report_notes' => $notes,
        ];
    }

    private static function mapObservation(array $obs): ?array
    {
        if (($obs['resourceType'] ?? '') !== 'Observation') {
            return null;
        }

        // ----- code -----
        $resultCode = '';
        $resultText = '';
        foreach ((array)($obs['code']['coding'] ?? []) as $coding) {
            $system = (string)($coding['system'] ?? '');
            $code = (string)($coding['code'] ?? '');
            if ($system === 'http://loinc.org' && $resultCode === '') {
                $resultCode = $code;
            }
            if ($resultText === '' && !empty($coding['display'])) {
                $resultText = $coding['display'];
            }
        }
        if ($resultCode === '') {
            $resultCode = (string)($obs['code']['coding'][0]['code'] ?? '');
        }
        if ($resultText === '') {
            $resultText = (string)($obs['code']['text'] ?? '');
        }

        // ----- value / units / data type -----
        $result = '';
        $units = '';
        $dataType = 'S';
        $value = $obs['valueQuantity'] ?? $obs['valueString'] ?? null;
        if (!empty($obs['valueQuantity']) && array_key_exists('value', $obs['valueQuantity'])) {
            $result = trim((string)$obs['valueQuantity']['value']);
            $units = (string)($obs['valueQuantity']['unit'] ?? '');
            if (is_numeric($result)) {
                $dataType = 'N';
            }
        } elseif (isset($obs['valueString'])) {
            $result = trim((string)$obs['valueString']);
            $dataType = (mb_strlen($result) > 60) ? 'L' : 'S';
        } elseif (!empty($obs['valueCodeableConcept']['text'])) {
            $result = trim((string)$obs['valueCodeableConcept']['text']);
        } elseif (!empty($obs['valueCodeableConcept']['coding'][0]['display'])) {
            $result = (string)$obs['valueCodeableConcept']['coding'][0]['display'];
        } elseif (isset($obs['valueInteger'])) {
            $result = (string)$obs['valueInteger'];
            $dataType = 'N';
        }
        if ($dataType === 'N' && $units === '' && !empty($obs['valueQuantity']['code'])) {
            $units = (string)$obs['valueQuantity']['code'];
        }

        // ----- reference range -----
        $range = '';
        $refRange = $obs['referenceRange'][0] ?? null;
        if ($refRange) {
            $low = self::quantityValue($refRange['low'] ?? null, $units);
            $high = self::quantityValue($refRange['high'] ?? null, $units);
            if ($low !== '' && $high !== '') {
                $range = "$low - $high";
            } elseif ($low !== '') {
                $range = "> $low";
            } elseif ($high !== '') {
                $range = "< $high";
            }
            if (empty($range) && !empty($refRange['text'])) {
                $range = (string)$refRange['text'];
            }
        }

        // ----- abnormal flag -----
        $abnormal = 'no';
        foreach ((array)($obs['interpretation'] ?? []) as $interpretation) {
            foreach ((array)($interpretation['coding'] ?? []) as $coding) {
                $code = strtoupper((string)($coding['code'] ?? ''));
                if (isset(self::ABNORMAL_MAP[$code])) {
                    $abnormal = self::ABNORMAL_MAP[$code];
                    break 2;
                }
            }
        }

        // ----- status / date / comments -----
        $status = strtolower((string)($obs['status'] ?? ''));
        $resultStatus = self::RESULT_STATUS_MAP[$status] ?? 'final';

        $date = null;
        foreach (['effectiveDateTime', 'issued'] as $key) {
            if (!empty($obs[$key])) {
                $date = self::fhirToOpenEmrDate($obs[$key]);
                break;
            }
        }
        if ($date === null && !empty($obs['effectivePeriod']['start'])) {
            $date = self::fhirToOpenEmrDate($obs['effectivePeriod']['start']);
        }

        $comments = null;
        if (!empty($obs['note'])) {
            $commentParts = [];
            foreach ($obs['note'] as $note) {
                if (!empty($note['text'])) {
                    $commentParts[] = $note['text'];
                }
            }
            if ($commentParts) {
                $comments = implode("\n", $commentParts);
            }
        }

        return [
            'result_code' => $resultCode,
            'result_text' => $resultText,
            'result_data_type' => $dataType,
            'date' => $date,
            'units' => $units,
            'result' => $result,
            'range' => $range,
            'abnormal' => $abnormal,
            'comments' => $comments,
            'result_status' => $resultStatus,
        ];
    }

    /**
     * Extract a numeric value from a quantity (value or high/low of a range).
     */
    private static function quantityValue(?array $qty, string $fallbackUnits): string
    {
        if (empty($qty) || !array_key_exists('value', $qty)) {
            return '';
        }
        $value = trim((string)$qty['value']);
        if (empty($value)) {
            return '';
        }
        $unit = (string)($qty['unit'] ?? '');
        return $unit !== '' && strcasecmp($unit, $fallbackUnits) !== 0 ? "$value $unit" : $value;
    }

    /**
     * Convert an FHIR instant/dateTime ("2026-09-05T14:30:00Z", "2026-09-05")
     * to OpenEMR datetime (UTC kept as-is for lab timestamps).
     */
    private static function fhirToOpenEmrDate(string $fhir): ?string
    {
        $trimmed = trim($fhir);
        if ($trimmed === '') {
            return null;
        }
        try {
            $ts = strtotime($trimmed);
            if ($ts === false) {
                return null;
            }
            return date('Y-m-d H:i:s', $ts);
        } catch (\Exception $e) {
            return null;
        }
    }
}