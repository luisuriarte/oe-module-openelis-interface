<?php

namespace OpenEMR\Modules\OpenElis;

class CodeMappingService
{
    /**
     * Dado un openemr_procedure_code, devuelve el openelis_test_id activo
     * correspondiente, o null si no existe mapeo activo.
     *
     * La UNIQUE KEY en openemr_procedure_code garantiza a lo sumo una fila.
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
     * Dado un openemr_procedure_code, devuelve el openelis_test_id activo.
     * Si no existe mapeo, devuelve el mismo procedure_code como fallback.
     */
    public static function resolveWithFallback(string $procedureCode): string
    {
        return self::resolveOpenElisTestId($procedureCode) ?? $procedureCode;
    }
}
