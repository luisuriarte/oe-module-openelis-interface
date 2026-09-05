<?php

namespace OpenEMR\Modules\OpenElis;

class CodeMappingService
{
    /**
     * mod_openelis_code_mapping is multi-lab: every row is scoped by
     * provider_id (procedure_providers.ppid). All resolvers take the target
     * providerId so an order for lab X never picks another lab's mapping when
     * several rows share the same openemr_procedure_code.
     *
     * provider_id = 0 rows are the legacy / unassigned mappings (the manual
     * mapping page writes them until it gains a lab selector). They act as the
     * generic fallback: queries scope to `provider_id IN (?, 0)` and prefer
     * the exact provider match; only if the provider has no dedicated row is
     * the legacy row returned (single-lab behavior). Imports (catalog_import)
     * always write the provider's real ppid, so multi-lab setups rarely rely
     * on the fallback.
     */

    /**
     * Returns the active openelis_test_id for a given procedure code and
     * target provider, or null.
     */
    public static function resolveOpenElisTestId(string $procedureCode, int $providerId): ?string
    {
        if (empty($procedureCode)) {
            return null;
        }

        $row = self::resolveMapping($procedureCode, $providerId);

        return $row['openelis_test_id'] ?? null;
    }

    /**
     * Returns the full mapping row for a given procedure code and target
     * provider, or null.
     * Fields: openelis_test_id, openelis_test_name, loinc_code,
     *         snomed_specimen, snomed_finding, units.
     */
    public static function resolveMapping(string $procedureCode, int $providerId): ?array
    {
        if (empty($procedureCode)) {
            return null;
        }

        $sql = "SELECT openelis_test_id, openelis_test_name,
                       loinc_code, snomed_specimen, snomed_finding, units,
                       provider_id
                FROM mod_openelis_code_mapping
                WHERE openemr_procedure_code = ? AND is_active = 1
                  AND provider_id IN (?, 0)
                ORDER BY (provider_id = ?) DESC, provider_id DESC
                LIMIT 1";
        $row = sqlQuery($sql, [$procedureCode, $providerId, $providerId]);

        if (!$row || empty($row['openelis_test_id'])) {
            return null;
        }

        return $row;
    }

    /**
     * Returns the LOINC code for a given procedure code and target provider,
     * or null.
     */
    public static function resolveLoincCode(string $procedureCode, int $providerId): ?string
    {
        $row = self::resolveMapping($procedureCode, $providerId);
        return !empty($row['loinc_code']) ? $row['loinc_code'] : null;
    }

    /**
     * Returns SNOMED specimen and finding codes for a given procedure code
     * and target provider.
     * ['specimen' => '...'|null, 'finding' => '...'|null]
     */
    public static function resolveSnomedCodes(string $procedureCode, int $providerId): array
    {
        $row = self::resolveMapping($procedureCode, $providerId);
        return [
            'specimen' => $row['snomed_specimen'] ?? null,
            'finding'  => $row['snomed_finding'] ?? null,
        ];
    }

    /**
     * Returns the units for a given procedure code and target provider, or null.
     */
    public static function resolveUnits(string $procedureCode, int $providerId): ?string
    {
        $row = self::resolveMapping($procedureCode, $providerId);
        return !empty($row['units']) ? $row['units'] : null;
    }

    /**
     * Returns the openelis_test_id for the target provider, or falls back to
     * the procedure code itself.
     */
    public static function resolveWithFallback(string $procedureCode, int $providerId): string
    {
        return self::resolveOpenElisTestId($procedureCode, $providerId) ?? $procedureCode;
    }
}