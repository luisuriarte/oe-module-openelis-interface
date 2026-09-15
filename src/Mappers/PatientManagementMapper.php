<?php

namespace OpenEMR\Modules\OpenElis\Mappers;

/**
 * Maps OpenEMR patient records to the OpenELIS Global 2 PatientManagementInfo
 * JSON structure used by the native REST endpoint:
 *   POST {OPENELIS_BASE_URL}/OpenELIS-Global/rest/PatientManagement
 *
 * OpenELIS Java class: org.openelisglobal.patient.action.bean.PatientManagementInfo
 */
class PatientManagementMapper
{
    /**
     * Map OpenEMR patient_data row to OpenELIS PatientManagementInfo payload.
     *
     * @param array  $patientData  Row from OpenEMR patient_data (fname, lname, DOB, sex, pid, pubpid, etc.)
     * @param string $patientPk    Existing OpenELIS patient PK if known, empty string for new patient
     * @return array
     */
    public static function toPatientManagementInfo(array $patientData, string $patientPk = ''): array
    {
        $fname = trim((string)($patientData['fname'] ?? ''));
        $lname = trim((string)($patientData['lname'] ?? ''));

        // Map sex/gender to 'M' or 'F'
        $rawSex = strtoupper(trim((string)($patientData['sex'] ?? '')));
        $gender = '';
        if ($rawSex === 'M' || $rawSex === 'MALE') {
            $gender = 'M';
        } elseif ($rawSex === 'F' || $rawSex === 'FEMALE') {
            $gender = 'F';
        }

        // Format DOB as dd/MM/yyyy (required by OpenELIS birthDateForDisplay)
        $dobRaw = trim((string)($patientData['DOB'] ?? ''));
        $birthDateForDisplay = '';
        if ($dobRaw !== '' && $dobRaw !== '0000-00-00') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dobRaw, $m)) {
                $birthDateForDisplay = sprintf('%02d/%02d/%04d', (int)$m[3], (int)$m[2], (int)$m[1]);
            } else {
                $ts = strtotime($dobRaw);
                if ($ts !== false) {
                    $birthDateForDisplay = date('d/m/Y', $ts);
                }
            }
        }

        $pubpid = trim((string)($patientData['pubpid'] ?? ''));
        $pidStr = trim((string)($patientData['pid'] ?? ''));

        return [
            'patientPK'           => $patientPk,
            'firstName'           => $fname,
            'lastName'            => $lname,
            'gender'              => $gender,
            'birthDateForDisplay' => $birthDateForDisplay,
            'nationalId'          => $pubpid !== '' ? $pubpid : $pidStr,
            'subjectNumber'       => $pidStr !== '' ? $pidStr : $pubpid,
            'STnumber'            => '',
            'guid'                => '',
            'patientIdentities'   => [],
            'patientContact'      => [
                'person' => (object)[],
            ],
        ];
    }
}
