<?php

namespace OpenEMR\Modules\OpenElis\Mappers;

class PatientMapper
{
    /**
     * Maps OpenEMR patient_data row to a FHIR R4 Patient resource
     * compatible with the OpenELIS Global profile.
     *
     * @param array $patientData Row from patient_data table
     * @return array FHIR Patient resource
     */
    public static function toFhirPatient(array $patientData): array
    {
        $patient = [
            'resourceType' => 'Patient',
            'active' => true,
            'identifier' => [],
            'name' => [
                [
                    'family' => $patientData['lname'] ?? '',
                    'given' => array_filter([$patientData['fname'] ?? '']),
                ],
            ],
            'gender' => self::mapGender($patientData['sex'] ?? ''),
        ];

        $idValue = !empty($patientData['pubpid']) ? trim((string)$patientData['pubpid']) : trim((string)($patientData['pid'] ?? ''));
        if ($idValue !== '') {
            $patient['identifier'][] = [
                'system' => 'http://openelis-global.org/pat_nationalId',
                'value' => $idValue,
            ];
            $patient['identifier'][] = [
                'system' => 'http://openemr.org/fhir/patient-id',
                'value' => (string)($patientData['pid'] ?? $idValue),
            ];
        }

        if (!empty($patientData['DOB']) && $patientData['DOB'] !== '0000-00-00') {
            $time = strtotime($patientData['DOB']);
            if ($time !== false && $time > 0) {
                $patient['birthDate'] = date('Y-m-d', $time);
            }
        }

        $addressLines = array_filter([$patientData['street'] ?? '']);
        if ($addressLines || !empty($patientData['city'])) {
            $address = [];
            if ($addressLines) {
                $address['line'] = $addressLines;
            }
            if (!empty($patientData['city'])) {
                $address['city'] = $patientData['city'];
            }
            if (!empty($patientData['state'])) {
                $address['state'] = $patientData['state'];
            }
            if (!empty($patientData['postal_code'])) {
                $address['postalCode'] = $patientData['postal_code'];
            }
            $patient['address'] = [$address];
        }

        if (!empty($patientData['phone_cell'])) {
            $patient['telecom'] = [
                [
                    'system' => 'phone',
                    'value' => $patientData['phone_cell'],
                    'use' => 'mobile',
                ],
            ];
        }

        return $patient;
    }

    private static function mapGender(string $sex): string
    {
        $sex = strtoupper(trim($sex));
        return match ($sex) {
            'M' => 'male',
            'F' => 'female',
            default => 'unknown',
        };
    }
}
