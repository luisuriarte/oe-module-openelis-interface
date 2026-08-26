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
     * @return array FHIR ServiceRequest resource
     */
    public static function toFhirServiceRequest(
        array $procedureOrder,
        array $orderCode,
        string $openelisPatientRef,
        string $openelisPractitionerRef
    ): array {
        $procedureCode = $orderCode['procedure_code'] ?? '';
        $loincCode = CodeMappingService::resolveLoincCode($procedureCode);

        $resource = [
            'resourceType' => 'ServiceRequest',
            'status' => 'active',
            'intent' => 'original-order',
            'priority' => self::mapPriority($procedureOrder['order_priority'] ?? ''),
            'code' => self::buildCodeConcept($loincCode, $orderCode['procedure_name'] ?? ''),
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
     * @return array FHIR Specimen resource
     */
    public static function toFhirSpecimen(string $openelisPatientRef, string $procedureCode): array
    {
        $snomedCodes = CodeMappingService::resolveSnomedCodes($procedureCode);

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

    private static function buildCodeConcept(?string $loincCode, string $procedureName): array
    {
        $concept = [];

        if ($loincCode) {
            $concept['coding'] = [
                [
                    'system' => 'http://loinc.org',
                    'code' => $loincCode,
                ],
            ];
        }

        if ($procedureName) {
            $concept['text'] = $procedureName;
        }

        return $concept;
    }
}
