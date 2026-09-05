<?php

namespace OpenEMR\Modules\OpenElis\Mappers;

use OpenEMR\Modules\OpenElis\CodeMappingService;

class OrderMapper
{
    /**
     * Maps an OpenEMR procedure_order + procedure_order_code to a FHIR R4 ServiceRequest.
     *
     * One ServiceRequest is generated per test (procedure_order_code).
     *
     * @param array $procedureOrder  Row from procedure_order
     * @param array $orderCode       Row from procedure_order_code
     * @param string $openelisPatientRef  Patient ID in OpenELIS (e.g. "Patient/uuid")
     * @param string $openelisPractitionerRef  Practitioner ID in OpenELIS
     * @param int $providerId        procedure_providers.ppid of the target lab —
     *                               scopes the mapping lookup (CodeMappingService)
     * @return array FHIR ServiceRequest resource
     */
    public static function toFhirServiceRequest(
        array $procedureOrder,
        array $orderCode,
        string $openelisPatientRef,
        string $openelisPractitionerRef,
        int $providerId
    ): array {
        $procedureCode = $orderCode['procedure_code'] ?? '';
        $mapping = CodeMappingService::resolveMapping($procedureCode, $providerId);

        $resource = [
            'resourceType' => 'ServiceRequest',
            'status' => 'active',
            'intent' => 'original-order',
            'priority' => self::mapPriority($procedureOrder['order_priority'] ?? ''),
            'code' => self::buildCodeConcept($mapping, $orderCode['procedure_name'] ?? ''),
            'subject' => ['reference' => $openelisPatientRef],
            'requester' => ['reference' => $openelisPractitionerRef],
        ];

        if (!empty($procedureOrder['date_ordered'])) {
            $resource['authoredOn'] = date('Y-m-d\TH:i:s', strtotime($procedureOrder['date_ordered']));
        }

        if (!empty($procedureOrder['order_diagnosis'])) {
            $resource['reasonCode'] = [
                [
                    'text' => $procedureOrder['order_diagnosis'],
                ],
            ];
        }

        return $resource;
    }

    /**
     * Maps an OpenEMR procedure_order_code to a FHIR R4 Specimen resource.
     *
     * @param string $openelisPatientRef  Patient ID in OpenELIS
     * @param string $procedureCode       OpenEMR procedure code
     * @param int $providerId        procedure_providers.ppid of the target lab —
     *                               scopes the SNOMED lookup (CodeMappingService)
     * @return array FHIR Specimen resource
     */
    public static function toFhirSpecimen(string $openelisPatientRef, string $procedureCode, int $providerId): array
    {
        $snomedCodes = CodeMappingService::resolveSnomedCodes($procedureCode, $providerId);

        $resource = [
            'resourceType' => 'Specimen',
            'status' => 'available',
            'subject' => ['reference' => $openelisPatientRef],
        ];

        if (!empty($snomedCodes['specimen'])) {
            $resource['type'] = [
                'coding' => [
                    [
                        'system' => 'http://snomed.info/sct',
                        'code' => $snomedCodes['specimen'],
                    ],
                ],
            ];
        }

        return $resource;
    }

    /**
     * Builds a FHIR Transaction Bundle from arrays of resources.
     *
     * @param array $entries  Array of ['resource' => [...], 'fullUrl' => '...']
     * @return array FHIR Bundle resource
     */
    public static function buildTransactionBundle(array $entries): array
    {
        $bundle = [
            'resourceType' => 'Bundle',
            'type' => 'transaction',
            'entry' => [],
        ];

        foreach ($entries as $entry) {
            $resource = $entry['resource'];
            $resourceType = $resource['resourceType'];
            $fullUrl = $entry['fullUrl'] ?? ($resourceType . '/' . ($resource['id'] ?? uniqid()));

            $bundleEntry = [
                'fullUrl' => $fullUrl,
                'resource' => $resource,
            ];

            // Determine the request method based on whether the resource has an id
            if (!empty($resource['id'])) {
                $bundleEntry['request'] = [
                    'method' => 'PUT',
                    'url' => $resourceType . '/' . $resource['id'],
                ];
            } else {
                $bundleEntry['request'] = [
                    'method' => 'POST',
                    'url' => $resourceType,
                ];
            }

            $bundle['entry'][] = $bundleEntry;
        }

        return $bundle;
    }

    private static function mapPriority(string $openemrPriority): string
    {
        $priority = strtolower(trim($openemrPriority));
        return match ($priority) {
            'stat', 'emergency' => 'stat',
            'urgent' => 'urgent',
            'asap' => 'asap',
            default => 'routine',
        };
    }

    /**
     * Builds a FHIR CodeableConcept for ServiceRequest.code.
     *
     * Always includes the openelis_test_id (or procedure code fallback) as the
     * primary coding — this is what OpenELIS uses to match against its test
     * catalog. If a LOINC code is also mapped, it is added as a second coding
     * entry within the same CodeableConcept (FHIR allows multiple codings).
     *
     * @param array|null $mapping  Full row from CodeMappingService::resolveMapping()
     * @param string $procedureName  Human-readable test name
     * @return array FHIR CodeableConcept
     */
    private static function buildCodeConcept(?array $mapping, string $procedureName): array
    {
        $codings = [];

        // Primary: openelis_test_id (or raw procedure code as fallback)
        $testId = $mapping['openelis_test_id'] ?? null;
        if ($testId) {
            $codings[] = [
                'system' => 'http://openelis-global.org/testId',
                'code' => $testId,
            ];
        }

        // Secondary: LOINC code if available
        $loincCode = $mapping['loinc_code'] ?? null;
        if ($loincCode) {
            $codings[] = [
                'system' => 'http://loinc.org',
                'code' => $loincCode,
            ];
        }

        $concept = [];
        if ($codings) {
            $concept['coding'] = $codings;
        }

        if ($procedureName) {
            $concept['text'] = $procedureName;
        }

        return $concept;
    }
}
