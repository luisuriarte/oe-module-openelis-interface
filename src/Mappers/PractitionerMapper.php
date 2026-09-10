<?php

namespace OpenEMR\Modules\OpenElis\Mappers;

class PractitionerMapper
{
    /**
     * Maps OpenEMR users row to a FHIR R4 Practitioner resource.
     *
     * @param array $userData Row from users table
     * @return array FHIR Practitioner resource
     */
    public static function toFhirPractitioner(array $userData): array
    {
        $practitioner = [
            'resourceType' => 'Practitioner',
            'active' => true,
            'name' => [
                [
                    'family' => $userData['lname'] ?? '',
                    'given' => array_filter([$userData['fname'] ?? '']),
                ],
            ],
        ];

        $identifierValue = !empty($userData['npi']) ? (string)$userData['npi'] : (string)($userData['id'] ?? '');
        if ($identifierValue !== '') {
            $practitioner['identifier'] = [
                [
                    'system' => !empty($userData['npi']) ? 'http://hl7.org/fhir/sid/us-npi' : 'http://openemr.org/fhir/practitioner-id',
                    'value' => $identifierValue,
                ],
            ];
        }

        return $practitioner;
    }
}
