<?php

namespace OpenEMR\Modules\OpenElis\Service;

use OpenEMR\Modules\OpenElis\Client\CatalogApiClient;

/**
 * Imports the OpenELIS test catalog (panels + ordered tests) into OpenEMR
 * from the REST test-catalog API, per lab provider.
 *
 * WHAT IT DOES
 *   For a single procedure_providers row (a lab):
 *     1. Reads the active panels and, per panel, the ordered tests.
 *     2. Cross-checks every panel member against the active-tests list
 *        (errorCount / findings):
 *          - errorCount > 0  OR any ERROR finding          -> EXCLUDED (e.g. an
 *            orphan test missing its sample-type link, SAMPLE_TYPE_LINKS).
 *          - WARNING-only findings (e.g. DUPLICATE_LOINC_DIFF_SPECIMEN) ->
 *            INCLUDED but reported so the admin is aware.
 *          - not present in the active list                 -> EXCLUDED (inactive).
 *     3. Creates/updates OpenEMR procedure_type rows:
 *          grp  OEP{providerId}-{panelId}   ... one per panel (parent = 0, top)
 *            ord OE{providerId}-T{testId}   ... one per valid test, hanging
 *                                              from its panel via `parent`
 *        and a mod_openelis_code_mapping row per test (import_source =
 *        'catalog_import') so the imported codes are immediately sendable.
 *
 * CODE SCHEME (deterministic / idempotent, never collides across labs)
 *   OE{providerId}-T{testId}   e.g. OE2-T42
 *   OEP{providerId}-{panelId}  e.g. OEP2-5
 *   procedure_code is the ONLY thing that must be unique; `name` is display
 *   only (and truncated to the column's 63 chars).
 *
 * CONFLICTS WITH MANUAL MAPPINGS
 *   A row in mod_openelis_code_mapping with import_source = 'manual' for the
 *   same (provider_id, openelis_test_id) means a human already tied a real
 *   OpenEMR procedure to this OpenELIS test (with a different, non-generated
 *   code). Comparing by openemr_procedure_code would never match, so the
 *   conflict is detected by (provider_id, openelis_test_id). Manual rows are
 *   never overwritten: re-imports only refresh openelis_panel_id /
 *   openelis_panel_name / imported_at and report a conflict for review.
 *
 * TRANSACTION
 *   The whole import for ONE provider runs inside a single transaction, so a
 *   failure in lab B never rolls back an already-confirmed import of lab A.
 */
class CatalogImportService
{
    /** @var CatalogApiClient|null Injected catalog client (overrides the one built from the provider row). */
    private ?CatalogApiClient $clientOverride;

    /** @var array|null Injected provider row (overrides the one read from the DB). */
    private ?array $providerOverride;

    public function __construct(?CatalogApiClient $clientOverride = null, ?array $providerOverride = null)
    {
        $this->clientOverride = $clientOverride;
        $this->providerOverride = $providerOverride;
    }

    /**
     * Import (or preview) the catalog for a single lab provider and create the
     * corresponding procedure_type tree + code mappings.
     *
     * @param int  $providerId  procedure_providers.ppid
     * @param bool $dryRun      true = preview only (zero writes)
     * @return array  Summary:
     *                [
     *                  provider_id, provider_name, dry_run,
     *                  panels => int,
     *                  tests_imported => int,
     *                  excluded_by_error => [testId => ['name','messages']],
     *                  inactive_missing => [testId => ['name','messages']],
     *                  tests_with_warnings => [testId => ['name','messages']],
     *                  conflicts => [testId => ['mapping_id','procedure_code','procedure_name']],
     *                  groups_created, groups_updated,
     *                  tests_created, tests_updated,
     *                  mappings_inserted, mappings_updated,
     *                  catalog_total, catalog_totalErrors,
     *                  catalog_totalWarnings, catalog_totalWithIssues,
     *                  catalog_totalInfo (optional, from listActiveTestsWithMeta)
     *                ]
     * @throws \RuntimeException  On validation, HTTP/auth or write failures.
     */
    public function importCatalogForProvider(int $providerId, bool $dryRun = false): array
    {
        $provider = $this->providerOverride ?? $this->loadProvider($providerId);

        // Validate the provider row every time, even when one was injected
        // (e.g. a lab whose credentials were cleared between a preview and its
        // confirmation must not slip through with an empty login).
        $this->validateProvider($provider);

        $client = $this->clientOverride ?? $this->buildClient($provider);

        if ($client === null) {
            throw new \RuntimeException(
                "No catalog client available for provider {$provider['ppid']}. "
                . "Set the catalog ADMIN credentials on the Procedure Providers edit form and try again."
            );
        }

        $providerId = (int)$provider['ppid'];
        $providerName = (string)$provider['name'];

        $summary = [
            'provider_id' => $providerId,
            'provider_name' => $providerName,
            'dry_run' => $dryRun,
            'panels' => 0,
            'tests_imported' => 0,
            'excluded_by_error' => [],
            'inactive_missing' => [],
            'tests_with_warnings' => [],
            'conflicts' => [],
            'groups_created' => 0,
            'groups_updated' => 0,
            'tests_created' => 0,
            'tests_updated' => 0,
            'mappings_inserted' => 0,
            'mappings_updated' => 0,
        ];

        $panels = $client->listPanels(false);

        // Use the aggregation-aware variant when the client provides it, so we
        // can surface the API's roll-up counts (totalErrors/totalWarnings/...)
        // in the import summary. Falls back to the plain list otherwise.
        if (method_exists($client, 'listActiveTestsWithMeta')) {
            $active = $client->listActiveTestsWithMeta();
            $activeTests = $this->indexActiveTests($active['tests']);
            foreach (['total', 'totalErrors', 'totalWarnings', 'totalWithIssues', 'totalInfo'] as $k) {
                if (isset($active['meta'][$k]) && $active['meta'][$k] !== null) {
                    $summary['catalog_' . $k] = $active['meta'][$k];
                }
            }
        } else {
            $activeTests = $this->indexActiveTests($client->listActiveTests());
        }

        $started = false;
        if (!$dryRun) {
            sqlBeginTrans();
            $started = true;
        }

        try {
            $panelSeq = 0;
            foreach ($panels as $panel) {
                $panelSeq++;
                $panelId = $this->pick($panel, ['panel_id', 'id', 'guid', 'code']);
                if ($panelId === null || $panelId === '') {
                    continue;
                }
                $panelName = $this->pick($panel, ['panel_name', 'name', 'name_en', 'name_es'])
                    ?? ('Panel ' . $panelId);

                $members = $client->listPanelTests($panelId);
                if (empty($members)) {
                    continue;
                }

                // IMPORTANT: `parent` on procedure_type references the real
                // procedure_type_id (the AUTO_INCREMENT primary key) of the
                // panel grp — NOT its procedure_code. upsertGroup() returns
                // that id and each ord hangs from it.
                $grpResult = $this->upsertGroup(
                    $this->panelCode($providerId, $panelId),
                    $this->truncateName($panelName),
                    $providerId,
                    $panelSeq,
                    $dryRun
                );
                $grpId = $grpResult['id'];

                $summary['panels']++;
                if ($grpResult['created']) {
                    $summary['groups_created']++;
                } else {
                    $summary['groups_updated']++;
                }

                $ordSeq = 0;
                foreach ($members as $member) {
                    $ordSeq++;
                    $testId = $this->pick($member, ['test_id', 'testId', 'id']);
                    if ($testId === null || $testId === '') {
                        continue;
                    }
                    $testName = $this->pick($member, ['test_name', 'testName', 'name', 'name_en', 'name_es'])
                        ?? ('Test ' . $testId);

                    $active = $activeTests[$testId] ?? null;
                    if ($active === null) {
                        $summary['inactive_missing'][$testId] = [
                            'name' => $testName,
                            'messages' => [
                                "Test is referenced by panel {$panelId} ({$panelName}) but is not in the active tests list.",
                            ],
                        ];
                        continue;
                    }

                    $classification = $this->classify($active);
                    $loinc = $this->pickStr($active, ['loinc', 'loinc_code', 'loincCode']);

                    if ($classification['error']) {
                        $summary['excluded_by_error'][$testId] = [
                            'name' => $testName,
                            'messages' => $classification['messages'],
                        ];
                        continue;
                    }

                    if ($classification['warning']) {
                        $summary['tests_with_warnings'][$testId] = [
                            'name' => $testName,
                            'messages' => $classification['messages'],
                        ];
                    }

                    // Keep the mapping page's autosuggest mirror
                    // (mod_openelis_test_catalog) fresh with every confirmed
                    // import — never on a preview.
                    if (!$dryRun) {
                        $this->mirrorUpsert($testId, $testName);
                    }

                    // Conflict: a human already mapped this (provider, test)
                    // manually. Refresh its panel metadata only, never the
                    // mapping itself, and skip creating an auto row.
                    $manual = $this->findManualMapping($providerId, $testId);
                    if ($manual !== null) {
                        $summary['conflicts'][$testId] = [
                            'mapping_id' => (int)$manual['id'],
                            'procedure_code' => $manual['openemr_procedure_code'],
                            'procedure_name' => $manual['openemr_procedure_name'],
                        ];
                        if (!$dryRun) {
                            sqlStatement(
                                "UPDATE mod_openelis_code_mapping
                                 SET openelis_panel_id = ?, openelis_panel_name = ?, imported_at = ?
                                 WHERE id = ?",
                                [$panelId, $this->truncateName($panelName, 255), date('Y-m-d H:i:s'), (int)$manual['id']]
                            );
                        }
                        continue;
                    }

                    $code = $this->testCode($providerId, $testId);
                    $this->upsertTest($code, $this->truncateName($testName), $loinc, $grpId, $providerId, $ordSeq, $dryRun)
                        ? $summary['tests_created']++ : $summary['tests_updated']++;

                    // Mapping upsert (auto-generated => always overwrite auto rows).
                    $inserted = $this->upsertMapping(
                        $code,
                        $this->truncateName($testName),
                        $testId,
                        $this->truncateName($testName),
                        $panelId,
                        $this->truncateName($panelName, 255),
                        $loinc,
                        $providerId,
                        $dryRun
                    );
                    if ($inserted) {
                        $summary['mappings_inserted']++;
                    } else {
                        $summary['mappings_updated']++;
                    }
                    $summary['tests_imported']++;
                }
            }

            if ($started) {
                sqlCommitTrans();
            }
        } catch (\Throwable $e) {
            if ($started) {
                sqlRollbackTrans();
            }
            throw $e;
        }

        return $summary;
    }

    // ---------------------------------------------------------------------
    // procedure_type writes
    // ---------------------------------------------------------------------

    /**
     * Insert or update the grp row for a panel and return its procedure_type_id.
     *
     * NOTE (do not mix up): the returned id is `procedure_type.procedure_type_id`
     * (the AUTO_INCREMENT primary key), which is what other rows reference in
     * their `parent` column — NOT the procedure_code string.
     *
     * @return array ['id' => int, 'created' => bool]
     */
    private function upsertGroup(string $code, string $name, int $providerId, int $seq, bool $dryRun): array
    {
        $id = $this->lookupProcedureTypeId($code);

        if ($id > 0) {
            if (!$dryRun) {
                sqlStatement(
                    "UPDATE procedure_type SET name = ?, parent = 0, lab_id = ?, activity = 1, seq = ?
                     WHERE procedure_type_id = ?",
                    [$name, $providerId, $seq, $id]
                );
            }
            return ['id' => $id, 'created' => false];
        }

        if (!$dryRun) {
            sqlStatement(
                "INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, activity, seq)
                 VALUES (0, ?, ?, ?, 'grp', 1, ?)",
                [$name, $providerId, $code, $seq]
            );
            $id = $this->lookupProcedureTypeId($code);
        }

        return ['id' => $id > 0 ? $id : -1, 'created' => true];
    }

    /**
     * Insert or update an orderable test under its panel grp.
     *
     * @return bool  True if a new row was created, false if an existing row was updated.
     */
    private function upsertTest(string $code, string $name, string $loinc, int $parentId, int $providerId, int $seq, bool $dryRun): bool
    {
        $id = $this->lookupProcedureTypeId($code);
        $standardCode = $loinc !== '' ? 'LOINC:' . $loinc : '';

        if ($id > 0) {
            if (!$dryRun) {
                sqlStatement(
                    "UPDATE procedure_type
                     SET name = ?, parent = ?, lab_id = ?, standard_code = ?, activity = 1, seq = ?
                     WHERE procedure_type_id = ?",
                    [$name, $parentId, $providerId, $standardCode, $seq, $id]
                );
            }
            return false;
        }

        if (!$dryRun) {
            sqlStatement(
                "INSERT INTO procedure_type (parent, name, lab_id, procedure_code, standard_code, procedure_type, activity, seq)
                 VALUES (?, ?, ?, ?, ?, 'ord', 1, ?)",
                [$parentId, $name, $providerId, $code, $standardCode, $seq]
            );
        }
        return true;
    }

    /**
     * Keep the mapping page's autosuggest mirror (mod_openelis_test_catalog)
     * in sync with the imported tests. Idempotent upsert keyed by the OpenELIS
     * test id. The REST payload exposes a single display name, so the same
     * value is stored in both language columns.
     */
    private function mirrorUpsert(string $testId, string $displayName): void
    {
        $name = $this->truncateName($displayName, 255);
        sqlStatement(
            "INSERT INTO mod_openelis_test_catalog (openelis_test_id, name_es, name_en)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name_es = VALUES(name_es), name_en = VALUES(name_en)",
            [$testId, $name, $name]
        );
    }

    /**
     * Find the procedure_type_id for a procedure_code, or 0 if it does not exist.
     * procedure_code is our deterministic per-lab key, so the lookup is by it.
     */
    private function lookupProcedureTypeId(string $code): int
    {
        $row = sqlQuery(
            "SELECT procedure_type_id AS id FROM procedure_type WHERE procedure_code = ? LIMIT 1",
            [$code]
        );
        return $row ? (int)$row['id'] : 0;
    }

    // ---------------------------------------------------------------------
    // mapping writes
    // ---------------------------------------------------------------------

    /**
     * Find a MANUAL mapping for the same (provider, openelis test). Manual rows
     * use real, human-chosen OpenEMR codes, so they are matched by test id.
     */
    private function findManualMapping(int $providerId, string $testId): ?array
    {
        return sqlQuery(
            "SELECT id, openemr_procedure_code, openemr_procedure_name
             FROM mod_openelis_code_mapping
             WHERE provider_id = ? AND openelis_test_id = ? AND import_source = 'manual'
             LIMIT 1",
            [$providerId, $testId]
        ) ?: null;
    }

    /**
     * Find an existing auto-generated mapping row (by generated code + provider).
     */
    private function findAutoMapping(int $providerId, string $code): ?array
    {
        return sqlQuery(
            "SELECT id FROM mod_openelis_code_mapping WHERE provider_id = ? AND openemr_procedure_code = ? LIMIT 1",
            [$providerId, $code]
        ) ?: null;
    }

    /**
     * Load + validate a provider row (used by the page to resolve credentials' existence).
     */
    public static function providerExists(int $providerId): bool
    {
        $row = sqlQuery(
            "SELECT ppid, active FROM procedure_providers WHERE ppid = ? AND active = 1",
            [$providerId]
        );
        return !empty($row);
    }

    /**
     * Insert or update an auto-generated mapping row.
     *
     * @return bool  True if inserted, false if an existing row was updated.
     */
    private function upsertMapping(
        string $procedureCode,
        string $procedureName,
        string $testId,
        string $testName,
        string $panelId,
        string $panelName,
        string $loinc,
        int $providerId,
        bool $dryRun
    ): bool {
        $existing = $this->findAutoMapping($providerId, $procedureCode);

        if ($existing) {
            if (!$dryRun) {
                sqlStatement(
                    "UPDATE mod_openelis_code_mapping
                     SET openemr_procedure_name = ?, openelis_test_id = ?, openelis_test_name = ?,
                         openelis_panel_id = ?, openelis_panel_name = ?, loinc_code = ?, is_active = 1,
                         import_source = 'catalog_import', imported_at = ?
                     WHERE id = ?",
                    [
                        $procedureName,
                        $testId,
                        $testName,
                        $panelId,
                        $panelName,
                        $loinc !== '' ? $loinc : null,
                        date('Y-m-d H:i:s'),
                        (int)$existing['id'],
                    ]
                );
            }
            return false;
        }

        if (!$dryRun) {
            sqlStatement(
                "INSERT INTO mod_openelis_code_mapping
                    (openemr_procedure_code, openemr_procedure_name, openelis_test_id, openelis_test_name,
                     openelis_panel_id, openelis_panel_name, loinc_code, is_active, import_source, imported_at, provider_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'catalog_import', ?, ?)",
                [
                    $procedureCode,
                    $procedureName,
                    $testId,
                    $testName,
                    $panelId,
                    $panelName,
                    $loinc !== '' ? $loinc : null,
                    date('Y-m-d H:i:s'),
                    $providerId,
                ]
            );
        }
        return true;
    }

    // ---------------------------------------------------------------------
    // source data handling
    // ---------------------------------------------------------------------

    /**
     * Key the active-tests list by test id and normalize each entry to
     * ['name', 'loinc', 'errorCount', 'findings'].
     */
    private function indexActiveTests(array $tests): array
    {
        $index = [];
        foreach ($tests as $t) {
            $testId = $this->pick($t, ['test_id', 'testId', 'id']);
            if ($testId === null || $testId === '') {
                continue;
            }
            $index[$testId] = [
                'name' => $this->pick($t, ['test_name', 'testName', 'name', 'name_en', 'name_es'])
                    ?? ('Test ' . $testId),
                'loinc' => (string)($this->pickStr($t, ['loinc', 'loinc_code', 'loincCode']) ?? ''),
                'errorCount' => (int)($this->pick($t, ['errorCount', 'error_count', 'errors']) ?? 0),
                'findings' => $t['findings'] ?? [],
            ];
        }
        return $index;
    }

    /**
     * Classify a test based on errorCount + findings.
     *
     * @return array ['error' => bool, 'warning' => bool, 'messages' => string[]]
     */
    private function classify(array $test): array
    {
        $messages = [];
        $hasError = $test['errorCount'] > 0;
        $hasWarning = false;

        $findings = $test['findings'] ?? [];
        foreach ((array)$findings as $finding) {
            [$message, $severity] = $this->parseFinding($finding);
            if ($message !== '') {
                $messages[] = $message;
            }
            if ($severity === 'error') {
                $hasError = true;
            } elseif ($severity === 'warning') {
                $hasWarning = true;
            }
        }

        return [
            'error' => $hasError,
            'warning' => $hasWarning && !$hasError,
            'messages' => $messages,
        ];
    }

    /**
     * Normalize a single finding entry to [message, severity].
     * Accepts a plain string ("SAMPLE_TYPE_LINKS", "DUPLICATE_LOINC_DIFF_SPECIMEN")
     * or an array with message/description/type + severity fields.
     */
    private function parseFinding($finding): array
    {
        if (is_string($finding)) {
            $finding = trim($finding);
            if ($finding === '' || stripos($finding, 'warning') !== false) {
                return [$finding, 'warning'];
            }
            if (stripos($finding, 'error') !== false || stripos($finding, 'orphan') !== false) {
                return [$finding, 'error'];
            }
            // Unknown plain string: treat as a non-blocking warning.
            return [$finding, 'warning'];
        }

        if (is_array($finding)) {
            $message = (string)($this->pickStr($finding, ['message', 'description', 'type', 'typeCode', 'code']) ?? '');
            $severityRaw = strtolower(trim((string)($this->pickStr($finding, ['severity', 'severityType', 'type']) ?? '')));
            if (in_array($severityRaw, ['error', 'errorseverity', 'severity_error'], true) || str_contains($severityRaw, 'error')) {
                return [$message, 'error'];
            }
            if (str_contains($severityRaw, 'warning')) {
                return [$message, 'warning'];
            }
            return [$message, 'warning'];
        }

        return ['', 'warning'];
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    /**
     * Deterministic, globally-unique orderable code for a test.
     * OE2-T42 = provider ppid 2, OpenELIS test 42.
     */
    private function testCode(int $providerId, string $testId): string
    {
        return 'OE' . $providerId . '-T' . $testId;
    }

    /**
     * Deterministic, globally-unique group code for a panel.
     * OEP2-5 = provider ppid 2, OpenELIS panel 5.
     */
    private function panelCode(int $providerId, string $panelId): string
    {
        return 'OEP' . $providerId . '-' . $panelId;
    }

    /**
     * Truncate a display name to a byte/char limit without cutting a word in
     * half. Uniqueness never depends on `name` — only on procedure_code — so
     * two tests truncating to the same text cannot collide.
     *
     * @param int $max  Column limit (procedure_type.name is varchar(63)).
     */
    private function truncateName(string $name, int $max = 63): string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($len <= $max) {
            return $name;
        }
        $cut = function_exists('mb_substr') ? mb_substr($name, 0, $max - 1) : substr($name, 0, $max - 1);
        $space = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ') : strrpos($cut, ' ');
        if ($space !== false) {
            $cut = function_exists('mb_substr') ? mb_substr($cut, 0, $space) : substr($cut, 0, $space);
        }
        return rtrim($cut, ',;:-') . '…';
    }

    /**
     * First non-empty value among candidate keys (scalar or numeric key), or null.
     */
    private function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return (string)$row[$key];
            }
        }
        return null;
    }

    /**
     * Same as pick() but keeps trailing zeros/length intact (no numeric coercion).
     */
    private function pickStr(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return (string)$row[$key];
            }
        }
        return null;
    }

    /**
     * Load + validate the lab provider row.
     *
     * @return array provider fields
     * @throws \RuntimeException
     */
    private function loadProvider(int $providerId): array
    {
        $provider = sqlQuery(
            "SELECT ppid, name, protocol, active, remote_host,
                    mod_openelis_catalog_login, mod_openelis_catalog_password
             FROM procedure_providers WHERE ppid = ?",
            [$providerId]
        );
        if (empty($provider)) {
            throw new \RuntimeException("Lab provider {$providerId} not found.");
        }
        return $provider;
    }

    /**
     * Validate the shared requirements of a lab provider before importing its
     * catalog. Called for BOTH overridden and database-loaded provider rows.
     *
     * @param array $provider
     * @throws \RuntimeException
     */
    private function validateProvider(array $provider): void
    {
        if (!$provider['active']) {
            throw new \RuntimeException("Lab provider {$provider['name']} is inactive.");
        }

        // NOTE: protocol must be 'WS' for this lab. This matches the value the
        // order-send flow validates (send_order_action.php). If you ever change
        // the provider's protocol value, update BOTH checks together.
        if (($provider['protocol'] ?? '') !== 'WS') {
            throw new \RuntimeException(
                "Lab provider {$provider['name']} protocol must be WS to import its catalog."
            );
        }
        if (empty($provider['remote_host'])) {
            throw new \RuntimeException("Lab provider {$provider['name']} has no remote_host configured.");
        }
        if (empty($provider['mod_openelis_catalog_login'])) {
            throw new \RuntimeException(
                "No catalog credentials configured for {$provider['name']}. "
                . "Set the OpenELIS ADMIN catalog user on the Procedure Providers edit form before importing."
            );
        }
    }

    /**
     * Build the catalog client from the provider's catalog credentials
     * (ADMIN role) — never from the operational Analyser Import login/password.
     *
     * @param array $provider
     * @return CatalogApiClient|null  null when clientOverride is unset but building fails
     */
    private function buildClient(array $provider): ?CatalogApiClient
    {
        if ($this->clientOverride !== null) {
            return $this->clientOverride;
        }
        return new CatalogApiClient(
            $provider['remote_host'],
            $provider['mod_openelis_catalog_login'],
            (string)($provider['mod_openelis_catalog_password'] ?? '')
        );
    }
}