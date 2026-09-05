<?php

namespace OpenEMR\Modules\OpenElis;

class CodeMappingService
{
    // TODO (future iteration): mod_openelis_code_mapping is now multi-lab —
    // rows carry provider_id (procedure_providers.ppid). The send flow must
    // eventually resolve mappings for the ORDER'S lab_id, i.e. add
    //   AND provider_id IN (0, <order.lab_id>)
    // to every query below (preferring the exact provider_id match). Deliberately
    // NOT implemented yet: the order flow keeps working unscoped for now, per the
    // current scope. When implemented, keep provider_id = 0 rows as the fallback
    // (legacy / unassigned).

    /**
     * Returns the active openelis_test_id for a given procedure code, or null.
     */
    public static function resolveOpenElisTestId(string $procedureCode): ?string
    {
        if (empty($procedureCode)) {
            return null;
        }

        $sql = "SELECT openelis_test_id
                FROM mod_openelis_code_mapping
                WHERE openemr_procedure_code = ? AND is_active = 1";
        $row = sqlQuery($sql, [$procedureCode]);

        if (!$row || empty($row['openelis_test_id'])) {
            return null;
        }

        return $row['openelis_test_id'];
    }

    /**
     * Returns the full mapping row for a given procedure code, or null.
     * Fields: openelis_test_id, openelis_test_name, loinc_code,
     *         snomed_specimen, snomed_finding, units.
     */
    public static function resolveMapping(string $procedureCode): ?array
    {
        if (empty($procedureCode)) {
            return null;
        }

        $sql = "SELECT openelis_test_id, openelis_test_name,
                       loinc_code, snomed_specimen, snomed_finding, units
                FROM mod_openelis_code_mapping
                WHERE openemr_procedure_code = ? AND is_active = 1";
        $row = sqlQuery($sql, [$procedureCode]);

        if (!$row || empty($row['openelis_test_id'])) {
            return null;
        }

        return $row;
    }

    /**
     * Returns the LOINC code for a given procedure code, or null.
     */
    public static function resolveLoincCode(string $procedureCode): ?string
    {
        $row = self::resolveMapping($procedureCode);
        return !empty($row['loinc_code']) ? $row['loinc_code'] : null;
    }

    /**
     * Returns SNOMED specimen and finding codes for a given procedure code.
     * ['specimen' => '...'|null, 'finding' => '...'|null]
     */
    public static function resolveSnomedCodes(string $procedureCode): array
    {
        $row = self::resolveMapping($procedureCode);
        return [
            'specimen' => $row['snomed_specimen'] ?? null,
            'finding'  => $row['snomed_finding'] ?? null,
        ];
    }

    /**
     * Returns the units for a given procedure code, or null.
     */
    public static function resolveUnits(string $procedureCode): ?string
    {
        $row = self::resolveMapping($procedureCode);
        return !empty($row['units']) ? $row['units'] : null;
    }

    /**
     * Returns the openelis_test_id, or falls back to the procedure code itself.
     */
    public static function resolveWithFallback(string $procedureCode): string
    {
        return self::resolveOpenElisTestId($procedureCode) ?? $procedureCode;
    }
}
