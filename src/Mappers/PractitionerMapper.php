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

        if (!empty($userData['npi'])) {
            $practitioner['identifier'] = [
                [
                    'system' => 'http://hl7.org/fhir/sid/us-npi',
                    'value' => $userData['npi'],
                ],
            ];
        }

        return $practitioner;
    }
}
