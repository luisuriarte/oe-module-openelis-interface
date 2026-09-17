<?php

namespace OpenEMR\Modules\OpenElis\Service;

use OpenEMR\Modules\OpenElis\Client\CatalogApiClient;

/**
 * Imports the OpenELIS test catalog into OpenEMR from the REST catalog API,
 * per lab provider.
 *
 * SOURCE
 *   A single GET /OpenELIS-Global/rest/TestCatalog returns the WHOLE catalog
 *   as one non-paginated document. OpenELIS does NOT expose panels as a list
 *   over REST (a telltale 404 from any /test-catalog/panels path), so the
 *   import is TEST-CENTRIC and groups tests under one grp per OpenELIS test
 *   SECTION (testUnit); the panel name each test reports is kept as
 *   informational mapping data only.
 *
 * WHAT IT DOES
 *   For a single procedure_providers row (a lab):
 *     1. Reads the whole catalog and keeps the ACTIVE tests (active flag =
 *        "Active"), ignoring inactive ones.
 *     2. Creates/updates OpenEMR procedure_type rows:
 *          grp  OEP{providerId}-SEC-{hash}  ... one per section (parent = 0, top)
 *            ord OE{providerId}-T{testId}   ... one per active test, hanging
 *                                              from its section via `parent`
 *        and a mod_openelis_code_mapping row per test (import_source =
 *        'catalog_import') so the imported codes are immediately sendable.
 *     3. For every test, extracts the catalog's sample type NAME
 *        (sampleType in the REST payload) and resolves it to a SNOMED-CT
 *        specimen code through mod_openelis_specimen_map. The resolved code
 *        is stored on the mapping row (snomed_specimen); sample types with
 *        no code yet are auto-INSERTed into the map (snomed_code NULL) and
 *        reported under the summary's `specimen_unmapped`, so that missing
 *        codes surface as a one-time curation task instead of a silent gap.
 *     4. DISPLAY SUFFIX: every imported grp/ord row carries the provider name
 *        as a visible suffix (`{Test} · {Lab}`) so that the same analysis
 *        ordered from different labs is distinguishable in OpenEMR's native
 *        procedure picker. The mapping row's openemr_procedure_name and the
 *        autosuggest mirror keep the CLEAN test name (never the suffix).
 *     5. RECONCILIATION: module-owned rows of THIS provider (OE{p}-T* ords and
 *        OEP{p}-* grps) that this import no longer references are DEACTIVATED
 *        (activity = 0) — never deleted — and their auto mappings pass to
 *        is_active = 0. Rows that come back (activity 0 -> 1) are logged as
 *        reactivated. Both transitions are reported in the summary so no test
 *        silently appears or disappears from the orderable tree. A dry-run
 *        previews what would change without writing anything. Group rows from
 *        the OLD panel-based scheme (OEP{p}-{panelId}) that no longer match a
 *        section are deactivated the same way.
 *
 * CODE SCHEME (deterministic / idempotent, never collides across labs)
 *   OE{providerId}-T{testId}          e.g. OE2-T42
 *   OEP{providerId}-SEC-{hash8}       e.g. OEP2-SEC-1a2b3c4d  (hash of the
 *                                     lowercase section name, stable across runs)
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

    /** procedure_type.name is varchar(63). */
    private const PROCEDURE_TYPE_NAME_MAX = 63;

    /**
     * OpenEMR order type stamped on every imported procedure_type row
     * (procedure_type_name). OpenELIS only catalogs lab tests, and its test
     * payload has no field that maps to the order_type list, so all imported
     * rows are classified as laboratory_test.
     */
    private const PROCEDURE_TYPE_ORDER_NAME = 'laboratory_test';

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
     *                  panels => int (test sections, one grp each),
     *                  tests_imported => int,
     *                  excluded_by_error => [testId => ['name','messages']],
     *                  inactive_missing => [testId => ['name','messages']],
     *                  tests_with_warnings => [testId => ['name','messages']],
     *                  conflicts => [testId => ['mapping_id','procedure_code','procedure_name']],
     *                  specimen_unmapped => [sample_type => ['tests' => int, 'example' => string]],
     *                  groups_created, groups_updated,
     *                  tests_created, tests_updated,
     *                  mappings_inserted, mappings_updated,
     *                  deactivated_panels => [code => name],
     *                  deactivated_tests  => [code => name],
     *                  reactivated_panels => [code => name],
     *                  reactivated_tests  => [code => name],
     *                  catalog_total (number of active tests seen)
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
            'specimen_unmapped' => [],
            'groups_created' => 0,
            'groups_updated' => 0,
            'tests_created' => 0,
            'tests_updated' => 0,
            'mappings_inserted' => 0,
            'mappings_updated' => 0,
            'deactivated_panels' => [],
            'deactivated_tests' => [],
            'reactivated_panels' => [],
            'reactivated_tests' => [],
        ];

        // ONE call to the classic catalog REST endpoint returns the WHOLE test
        // catalog in a single non-paginated document (the API does not expose a
        // panels list). The import is therefore test-centric: active tests are
        // grouped under one grp per OpenELIS test section (testUnit), and the
        // panel name the catalog reports per test is kept as informational data.
        $catalog = $client->listCatalog();

        $sections = [];
        $activeCount = 0;
        foreach ($catalog as $raw) {
            $testId = $this->pick($raw, ['test_id', 'testId', 'id']);
            if ($testId === null || $testId === '') {
                continue;
            }
            if (!$this->isActiveFlag($raw['active'] ?? null)) {
                continue;
            }
            $activeCount++;
            $testName = $this->testName($raw, $testId);
            $sectionName = $this->sectionName((string)($raw['testUnit'] ?? ''));
            $key = $this->sectionKey($sectionName);
            $test = [
                'test_id' => $testId,
                'name' => $testName,
                'loinc' => $this->pickStr($raw, ['loinc', 'loinc_code', 'loincCode']) ?? '',
                'sample_type' => $this->pickStr($raw, ['sampleType', 'sample_type', 'typeOfSample', 'sample']) ?? '',
                'panel' => $this->panelString((string)($raw['panel'] ?? '')),
                'sort_order' => (int)($raw['testSortOrder'] ?? 0),
            ];
            if (!isset($sections[$key])) {
                $sections[$key] = ['name' => $sectionName, 'tests' => []];
            }
            $sections[$key]['tests'][] = $test;
        }
        ksort($sections, SORT_STRING);

        $summary['catalog_total'] = $activeCount;

        $started = false;
        if (!$dryRun) {
            sqlBeginTrans();
            $started = true;
        }

        try {
            $panelSeq = 0;
            $seenPanelCodes = [];
            $seenTestCodes = [];
            foreach ($sections as $section) {
                $panelSeq++;
                // IMPORTANT: `parent` on procedure_type references the real
                // procedure_type_id (the AUTO_INCREMENT primary key) of the
                // section grp — NOT its procedure_code. upsertGroup() returns
                // that id and each ord hangs from it.
                $sectionCode = $this->sectionCode($providerId, $section['name']);
                $sectionDisplayName = $this->displayName($section['name'], $providerName);
                $grpResult = $this->upsertGroup(
                    $sectionCode,
                    $sectionDisplayName,
                    $providerId,
                    $panelSeq,
                    $dryRun
                );
                $grpId = $grpResult['id'];
                $seenPanelCodes[$sectionCode] = true;

                $summary['panels']++;
                if ($grpResult['created']) {
                    $summary['groups_created']++;
                } else {
                    $summary['groups_updated']++;
                }
                if ($grpResult['reactivated']) {
                    $summary['reactivated_panels'][$sectionCode] = $sectionDisplayName;
                }

                $members = $section['tests'];
                usort($members, static function (array $a, array $b): int {
                    $byOrder = ($a['sort_order'] <=> $b['sort_order']);
                    if ($byOrder !== 0) {
                        return $byOrder;
                    }
                    return strcasecmp($a['name'], $b['name']);
                });

                $ordSeq = 0;
                foreach ($members as $test) {
                    $ordSeq++;
                    $testId = $test['test_id'];
                    $testName = $test['name'];
                    $loinc = (string)$test['loinc'];
                    $sampleType = (string)$test['sample_type'];
                    $panelName = (string)$test['panel'];

                    // Every active test counts as "seen" — even the ones with a
                    // manual-mapping conflict below — so a transient catalog
                    // read error never deactivates a previously-imported row.
                    $code = $this->testCode($providerId, $testId);
                    $seenTestCodes[$code] = true;

                    // Resolve the OpenELIS sample type NAME to a SNOMED-CT code
                    // via the once-only translation table. Unmapped sample types
                    // are registered in the table (NULL code) and reported so
                    // they can be curated a single time for every test that
                    // shares the specimen.
                    $snomed = $sampleType !== '' ? $this->specimenSnomedFor($sampleType) : '';
                    if ($sampleType !== '' && $snomed === '') {
                        if (!isset($summary['specimen_unmapped'][$sampleType])) {
                            $summary['specimen_unmapped'][$sampleType] = ['tests' => 0, 'example' => ''];
                        }
                        $summary['specimen_unmapped'][$sampleType]['tests']++;
                        if ($summary['specimen_unmapped'][$sampleType]['example'] === '') {
                            $summary['specimen_unmapped'][$sampleType]['example'] = $testName;
                        }
                    }

                    // Keep the mapping page's autosuggest mirror
                    // (mod_openelis_test_catalog) fresh with every confirmed
                    // import — never on a preview.
                    if (!$dryRun) {
                        $this->mirrorUpsert($testId, $testName, $sampleType);
                        if ($sampleType !== '') {
                            $this->ensureSpecimenType($sampleType);
                        }
                    }

                    // Conflict: a human already mapped this (provider, test)
                    // manually. Refresh its section/panel metadata only, never
                    // the mapping itself, and skip creating an auto row.
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
                                [
                                    $sectionCode,
                                    $this->truncateName($panelName !== '' ? $panelName : $section['name'], 255),
                                    date('Y-m-d H:i:s'),
                                    (int)$manual['id'],
                                ]
                            );
                        }
                        continue;
                    }

                    $ups = $this->upsertTest(
                        $code,
                        $this->displayName($testName, $providerName),
                        $loinc,
                        $grpId,
                        $providerId,
                        $ordSeq,
                        $dryRun
                    );
                    if ($ups['created']) {
                        $summary['tests_created']++;
                    } else {
                        $summary['tests_updated']++;
                    }
                    if ($ups['reactivated']) {
                        $summary['reactivated_tests'][$code] = $this->displayName($testName, $providerName);
                    }

                    // Mapping upsert (auto-generated => always overwrite auto rows).
                    // The mapping's openemr_procedure_name keeps the CLEAN test
                    // name (no ` · {Lab}` suffix): reports/results must not show it.
                    // openelis_panel_id carries the section grp code and
                    // openelis_panel_name the panel display string when the
                    // catalog reports one (informational in both cases).
                    $inserted = $this->upsertMapping(
                        $code,
                        $this->truncateName($testName),
                        $testId,
                        $this->truncateName($testName),
                        $sectionCode,
                        $this->truncateName($panelName !== '' ? $panelName : $section['name'], 255),
                        $loinc,
                        $snomed,
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

            // Deactivate module-owned rows of this provider that the catalog no
            // longer references (never delete them). Runs AFTER the loop so the
            // seen-sets are complete; faithful on dry-run (reads only).
            $this->reconcileAbsent(
                $providerId,
                $seenPanelCodes,
                $seenTestCodes,
                $dryRun,
                $summary
            );

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
     * Insert or update the grp row for a test SECTION and return its
     * procedure_type_id.
     *
     * NOTE (do not mix up): the returned id is `procedure_type.procedure_type_id`
     * (the AUTO_INCREMENT primary key), which is what other rows reference in
     * their `parent` column — NOT the procedure_code string.
     *
     * @return array ['id' => int, 'created' => bool, 'reactivated' => bool]
     *               'reactivated' = true when an existing row was activity=0 and
     *               is being set back to activity=1 (logged by the caller).
     */
    private function upsertGroup(string $code, string $name, int $providerId, int $seq, bool $dryRun): array
    {
        $found = $this->lookupProcedureType($code);
        $id = $found['id'] ?? 0;

        if ($id > 0) {
            $reactivated = (int)($found['activity'] ?? 1) === 0;
            if (!$dryRun) {
                sqlStatement(
                    "UPDATE procedure_type SET name = ?, parent = 0, lab_id = ?, procedure_type_name = ?, activity = 1, seq = ?
                     WHERE procedure_type_id = ?",
                    [$name, $providerId, self::PROCEDURE_TYPE_ORDER_NAME, $seq, $id]
                );
            }
            return ['id' => $id, 'created' => false, 'reactivated' => $reactivated];
        }

        if (!$dryRun) {
            sqlStatement(
                "INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type_name, procedure_type, activity, seq)
                 VALUES (0, ?, ?, ?, ?, 'grp', 1, ?)",
                [$name, $providerId, $code, self::PROCEDURE_TYPE_ORDER_NAME, $seq]
            );
            $found = $this->lookupProcedureType($code);
            $id = $found['id'] ?? 0;
        }

        return ['id' => $id > 0 ? $id : -1, 'created' => true, 'reactivated' => false];
    }

    /**
     * Insert or update an orderable test under its section grp. A test whose
     * section changed between syncs is re-hung under its NEW section: the
     * UPDATE always reassigns `parent`, so it can never be left orphaned while
     * it exists.
     *
     * @return array ['created' => bool, 'reactivated' => bool]
     */
    private function upsertTest(string $code, string $name, string $loinc, int $parentId, int $providerId, int $seq, bool $dryRun): array
    {
        $found = $this->lookupProcedureType($code);
        $id = $found['id'] ?? 0;
        $standardCode = $loinc !== '' ? 'LOINC:' . $loinc : '';

        if ($id > 0) {
            $reactivated = (int)($found['activity'] ?? 1) === 0;
            if (!$dryRun) {
                sqlStatement(
                    "UPDATE procedure_type
                     SET name = ?, parent = ?, lab_id = ?, standard_code = ?, procedure_type_name = ?, activity = 1, seq = ?
                     WHERE procedure_type_id = ?",
                    [$name, $parentId, $providerId, $standardCode, self::PROCEDURE_TYPE_ORDER_NAME, $seq, $id]
                );
            }
            return ['created' => false, 'reactivated' => $reactivated];
        }

        if (!$dryRun) {
            sqlStatement(
                "INSERT INTO procedure_type (parent, name, lab_id, procedure_code, standard_code, procedure_type_name, procedure_type, activity, seq)
                 VALUES (?, ?, ?, ?, ?, ?, 'ord', 1, ?)",
                [$parentId, $name, $providerId, $code, $standardCode, self::PROCEDURE_TYPE_ORDER_NAME, $seq]
            );
        }
        return ['created' => true, 'reactivated' => false];
    }

    /**
     * Keep the mapping page's autosuggest mirror (mod_openelis_test_catalog)
     * in sync with the imported tests. Idempotent upsert keyed by the OpenELIS
     * test id. The REST payload exposes a single display name, so the same
     * value is stored in both language columns.
     */
    private function mirrorUpsert(string $testId, string $displayName, string $sampleType = ''): void
    {
        $name = $this->truncateName($displayName, 255);
        $sampleType = $this->truncateName($sampleType, 64);
        sqlStatement(
            "INSERT INTO mod_openelis_test_catalog (openelis_test_id, name_es, name_en, sample_type)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name_es = VALUES(name_es), name_en = VALUES(name_en),
                 sample_type = VALUES(sample_type)",
            [$testId, $name, $name, $sampleType]
        );
    }

    /**
     * Find the SNOMED-CT specimen code for an OpenELIS sample type NAME via the
     * once-only translation table mod_openelis_specimen_map.
     *
     * @return string  The mapped snomed_code, or '' when the sample type is
     *                 unknown / not yet curated.
     */
    private function specimenSnomedFor(string $sampleType): string
    {
        $sampleType = trim($sampleType);
        if ($sampleType === '') {
            return '';
        }
        $row = sqlQuery(
            "SELECT snomed_code FROM mod_openelis_specimen_map WHERE sample_type = ? LIMIT 1",
            [$sampleType]
        );
        if (!$row || trim((string)($row['snomed_code'] ?? '')) === '') {
            return '';
        }
        return trim((string)$row['snomed_code']);
    }

    /**
     * Make sure an OpenELIS sample type NAME has a row in the translation table,
     * so a missing SNOMED code is discoverable and can be curated once. Existing
     * rows (including their curated snomed_code) are never touched.
     */
    private function ensureSpecimenType(string $sampleType): void
    {
        $sampleType = trim($sampleType);
        if ($sampleType === '') {
            return;
        }
        sqlStatement(
            "INSERT INTO mod_openelis_specimen_map (sample_type)
             VALUES (?)
             ON DUPLICATE KEY UPDATE sample_type = sample_type",
            [$sampleType]
        );
    }

    /**
     * Find the procedure_type row by procedure_code, or []
     * procedure_code is our deterministic per-lab key, so the lookup is by it.
     *
     * @return array ['id' => int, 'activity' => int] or ['id' => 0, 'activity' => -1] when absent.
     */
    private function lookupProcedureType(string $code): array
    {
        $row = sqlQuery(
            "SELECT procedure_type_id AS id, activity FROM procedure_type WHERE procedure_code = ? LIMIT 1",
            [$code]
        );
        return $row
            ? ['id' => (int)$row['id'], 'activity' => (int)$row['activity']]
            : ['id' => 0, 'activity' => -1];
    }

    /**
     * Deactivate (never delete) module-owned procedure_type rows of this
     * provider that this sync no longer references, and pull the affected auto
     * mappings to is_active = 0 so the send flow and the mapping page stop
     * offering them. Both transitions (deactivated + the reactivations already
     * logged by upsertGroup/upsertTest) are reported in the summary.
     *
     * SAFETY GUARDS (transient API failures must never wipe a live tree):
     *  - Only runs when the import actually saw sections AND tests: an empty or
     *    failed catalog read deactivates nothing.
     *  - Dry-run: reports exactly what WOULD be deactivated, zero writes.
     *
     * @param array $summary  Passed by reference; populated with
     *                        deactivated_panels / deactivated_tests [code => name].
     *                        `deactivated_panels` holds section grps (and any
     *                        residue of the old panel-based scheme).
     */
    private function reconcileAbsent(
        int $providerId,
        array $seenPanelCodes,
        array $seenTestCodes,
        bool $dryRun,
        array &$summary
    ): void {
        if (empty($seenPanelCodes) || empty($seenTestCodes)) {
            return;
        }
        // The seen-sets are already [procedure_code => true] (the importer
        // stamps them per section/test as it walks the catalog).
        $seenPanels = $seenPanelCodes;
        $seenTests = $seenTestCodes;

        $rs = sqlStatement(
            "SELECT procedure_type_id, procedure_code, procedure_type, name, activity
             FROM procedure_type
             WHERE lab_id = ? AND (procedure_code LIKE ? OR procedure_code LIKE ?)",
            [$providerId, 'OEP' . $providerId . '-%', 'OE' . $providerId . '-T%']
        );
        while ($row = sqlFetchArray($rs)) {
            $code = trim((string)$row['procedure_code']);
            $isPanel = (string)$row['procedure_type'] === 'grp';
            if ((int)$row['activity'] !== 1) {
                continue;
            }
            if ($isPanel ? isset($seenPanels[$code]) : isset($seenTests[$code])) {
                continue;
            }

            if (!$dryRun) {
                sqlStatement(
                    "UPDATE procedure_type SET activity = 0 WHERE procedure_type_id = ?",
                    [(int)$row['procedure_type_id']]
                );
                if (!$isPanel) {
                    sqlStatement(
                        "UPDATE mod_openelis_code_mapping SET is_active = 0
                         WHERE provider_id = ? AND openemr_procedure_code = ? AND import_source = 'catalog_import'",
                        [$providerId, $code]
                    );
                }
            }
            $summary[$isPanel ? 'deactivated_panels' : 'deactivated_tests'][$code] = (string)$row['name'];
        }
    }

    /**
     * Build the display name stored on the procedure_type row: {name} · {Lab}.
     * With several labs each pointing to its own OpenELIS, the suffix is what
     * tells "Hematología Básica" apart in OpenEMR's native order picker. The
     * mapping's openemr_procedure_name and the autosuggest mirror keep the
     * clean name — reports/results must never render the suffix.
     *
     * The suffix reserves its own space (up to 24 chars of provider name), so
     * even a very long test/panel name gets truncated in the middle and the
     * " · {Lab}" tail ALWAYS survives — losing the lab name would defeat the
     * feature. Everything still fits the varchar(63) column.
     */
    private function displayName(string $name, string $providerName): string
    {
        $provider = trim($providerName);
        if ($provider === '') {
            return $this->truncateName($name, self::PROCEDURE_TYPE_NAME_MAX);
        }
        $sep = ' · ';
        $providerShort = $this->truncateName($provider, 24);
        $maxBase = self::PROCEDURE_TYPE_NAME_MAX - $this->charLen($sep) - $this->charLen($providerShort);
        return $this->truncateName($name, $maxBase) . $sep . $providerShort;
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
     * @param string $sectionCode    Procedure_code of the section grp the test
     *                               hangs from; stored in openelis_panel_id
     *                               (informational, never compared).
     * @param string $sectionDisplay Display label stored in openelis_panel_name:
     *                               the test's panel string from the catalog when
     *                               present, else the section name (informational).
     * @param string $snomed  SNOMED-CT specimen code resolved from the catalog
     *                        sample type via mod_openelis_specimen_map; ''
     *                        keeps the stored value on update (never wipes a
     *                        previously-resolved code because a transient map
     *                        miss).
     * @return bool  True if inserted, false if an existing row was updated.
     */
    private function upsertMapping(
        string $procedureCode,
        string $procedureName,
        string $testId,
        string $testName,
        string $sectionCode,
        string $sectionDisplay,
        string $loinc,
        string $snomed,
        int $providerId,
        bool $dryRun
    ): bool {
        $existing = $this->findAutoMapping($providerId, $procedureCode);
        $snomed = trim($snomed);

        if ($existing) {
            if (!$dryRun) {
                sqlStatement(
                    "UPDATE mod_openelis_code_mapping
                     SET openemr_procedure_name = ?, openelis_test_id = ?, openelis_test_name = ?,
                         openelis_panel_id = ?, openelis_panel_name = ?, loinc_code = ?,
                         snomed_specimen = CASE WHEN ? <> '' THEN ? ELSE snomed_specimen END,
                         is_active = 1, import_source = 'catalog_import', imported_at = ?
                     WHERE id = ?",
                    [
                        $procedureName,
                        $testId,
                        $testName,
                        $sectionCode,
                        $sectionDisplay,
                        $loinc !== '' ? $loinc : null,
                        $snomed,
                        $snomed,
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
                     openelis_panel_id, openelis_panel_name, loinc_code, snomed_specimen,
                     is_active, import_source, imported_at, provider_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'catalog_import', ?, ?)",
                [
                    $procedureCode,
                    $procedureName,
                    $testId,
                    $testName,
                    $sectionCode,
                    $sectionDisplay,
                    $loinc !== '' ? $loinc : null,
                    $snomed !== '' ? $snomed : null,
                    date('Y-m-d H:i:s'),
                    $providerId,
                ]
            );
        }
        return true;
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
     * Deterministic, globally-unique group code for a test section.
     * OEP2-SEC-1a2b3c4d = provider ppid 2, section whose lowercase name hashes
     * to 1a2b3c4d. The hash (not the raw name) keeps the code short and ASCII;
     * it is stable across runs, so re-importing is idempotent. The OEP{p}-%
     * prefix keeps the row inside the reconciliation scan.
     */
    private function sectionCode(int $providerId, string $sectionName): string
    {
        return 'OEP' . $providerId . '-SEC-' . substr(md5($this->sectionKey($sectionName)), 0, 8);
    }

    /**
     * Normalized key for a section name (used to bucket tests and to seed the
     * group code hash). Empty/whitespace collapses to 'general'.
     */
    private function sectionKey(string $sectionName): string
    {
        $name = trim($sectionName);
        return $name === '' ? 'general' : strtolower($name);
    }

    /**
     * Display name of a test's section, with a stable fallback for tests whose
     * catalog entry carries no section (empty testUnit).
     */
    private function sectionName(string $sectionName): string
    {
        $name = trim($sectionName);
        return $name === '' || strcasecmp($name, 'n/a') === 0 ? 'General' : $name;
    }

    /**
     * The catalog reports the panels a test belongs to as a comma-separated
     * display string ("None" when it belongs to none). Normalize "None"/empty
     * to '', keeping the informative value otherwise.
     */
    private function panelString(string $panel): string
    {
        $panel = trim($panel);
        if ($panel === '' || strcasecmp($panel, 'none') === 0) {
            return '';
        }
        return $panel;
    }

    /**
     * Interpret the catalog's active flag. The classic endpoint sends the
     * strings "Active" / "Not active"; booleans and 0/1 are accepted too.
     */
    private function isActiveFlag($flag): bool
    {
        if (is_bool($flag)) {
            return $flag;
        }
        if (is_numeric($flag)) {
            return (int)$flag === 1;
        }
        $s = strtolower(trim((string)$flag));
        return in_array($s, ['active', '1', 'true', 'y', 'yes'], true);
    }

    /**
     * Character length of a string (mb fallback for php -n / no mbstring).
     */
    private function charLen(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
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
            if (array_key_exists($key, $row) && is_scalar($row[$key]) && (string)$row[$key] !== '') {
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
            if (array_key_exists($key, $row) && is_scalar($row[$key])) {
                return (string)$row[$key];
            }
        }
        return null;
    }

    /**
     * Extract a usable display name from a catalog entry.
     *
     * The classic endpoint returns the name under `localization`, which is an
     * OBJECT (never a plain string): either a small DTO with spanish/english
     * fields or a localizedNames language -> string map. We prefer a Spanish
     * label, fall back to English, then to any language the map provides.
     *
     * @param array  $raw   Catalog entry
     * @param string $testId Fallback suffix for "Test {id}" placeholders
     * @return string
     */
    private function testName(array $raw, string $testId): string
    {
        foreach (['test_name', 'testName', 'name', 'name_en', 'name_es'] as $key) {
            $v = $raw[$key] ?? null;
            if (is_scalar($v) && trim((string)$v) !== '') {
                return (string)$v;
            }
        }

        $loc = $raw['localization'] ?? null;
        if (is_array($loc)) {
            foreach (['localizedNames', 'names'] as $mapKey) {
                $map = $loc[$mapKey] ?? null;
                if (is_array($map)) {
                    foreach (['es', 'es_AR', 'es_419', 'spanish', 'en', 'english'] as $lang) {
                        $v = $map[$lang] ?? null;
                        if (is_scalar($v) && trim((string)$v) !== '') {
                            return (string)$v;
                        }
                    }
                    foreach ($map as $v) {
                        if (is_scalar($v) && trim((string)$v) !== '') {
                            return (string)$v;
                        }
                    }
                }
            }
            foreach (['spanish', 'es', 'es_AR', 'english', 'en'] as $field) {
                $v = $loc[$field] ?? null;
                if (is_scalar($v) && trim((string)$v) !== '') {
                    return (string)$v;
                }
            }
        }

        return 'Test ' . $testId;
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
                "No catalog credentials configured for {$provider['name']} (provider #{$provider['ppid']}). "
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