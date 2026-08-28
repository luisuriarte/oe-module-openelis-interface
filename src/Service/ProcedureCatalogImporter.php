<?php

namespace OpenEMR\Modules\OpenElis\Service;

/**
 * Imports the OpenELIS test catalog (CSV) into OpenEMR's procedure_type table.
 *
 * WHY CSV AND NOT DIRECT SQL AGAINST OPENELIS:
 *   The lab does not share OpenELIS database credentials, and the REST API
 *   (/OpenELIS-Global/rest/TestNamesProvider) returns only a test's name by id —
 *   it does NOT expose the panel structure or the section grouping. The lab
 *   therefore exports the catalog as two comma-separated files, which the admin
 *   drops next to the module's deployed scripts and imports from the OpenELIS
 *   Settings page:
 *
 *     catalog.csv   test_id,test_name,loinc,section_name,is_active
 *     panels.csv    panel_id,panel_name,test_id,test_name
 *
 * BUILDING THE HIERARCHY
 *   OpenEMR's procedure_type table stores a single parent per row (column
 *   `parent`), so a child cannot hang from two groups at once. To stay faithful
 *   to the catalog while respecting that constraint (and because in real data
 *   every panel groups tests from a single section):
 *
 *     grp  per section                     e.g. "Hematology"
 *     grp  per panel, nested in its section  e.g. "Hematology" > "NFS"
 *     ord  per test; hangs from its panel when it belongs to one, otherwise
 *          directly from its section group.
 *
 *   Codes follow the convention agreed with the lab integration:
 *     OES-<section>  section group (slugified)
 *     OEP-<panel_id> panel group
 *     OE-<test_id>   individual test
 *
 *   The LOINC code (present in the catalog) is stored in procedure_type.standard_code
 *   as "LOINC:<code>" so the mapping/FHIR flow can reuse it.
 *
 * IDEMPOTENCE
 *   procedure_code is treated as unique per lab; re-importing updates rows in
 *   place instead of duplicating them. As a convenience the importer also upserts
 *   a mod_openelis_code_mapping row per test (procedure_code -> openelis_test_id),
 *   so imported tests are immediately usable by the send flow.
 */
class ProcedureCatalogImporter
{
    public const CATALOG_FILE = 'catalog.csv';
    public const PANELS_FILE = 'panels.csv';

    /** @var string Directory where the CSV files are expected. */
    private string $csvDir;

    /** @var int|null Lab provider (procedure_providers.ppid) stamped on imported rows. */
    private ?int $labId;

    public function __construct(string $csvDir, ?int $labId = null)
    {
        $this->csvDir = rtrim($csvDir, '/\\');
        $this->labId = $labId;
    }

    /**
     * Run the import.
     *
     * @param int|null $labId  Force the lab provider id (overrides the one from the
     *                         constructor when given).
     * @return array  ['sections'=>int,'panels'=>int,'tests'=>int,'mappings'=>int,'warnings'=>string[]]
     * @throws \RuntimeException  If a CSV file is missing/unreadable/malformed.
     */
    public function import(?int $labId = null): array
    {
        $labId = $labId ?? $this->labId ?? 0;

        $tests = $this->readCatalog();
        $panels = $this->readPanels();

        $warnings = [];
        $sectionCount = 0;
        $panelCount = 0;
        $testCount = 0;
        $mappingCount = 0;

        // Group tests by section, preserving catalog order.
        $sections = [];
        foreach ($tests as $t) {
            if ($t['is_active'] !== 'Y') {
                continue;
            }
            $sections[$t['section_name']][] = $t;
        }

        // Which section does each test_id belong to?
        $testSection = [];
        foreach ($tests as $t) {
            $testSection[$t['test_id']] = $t['section_name'];
        }

        // Which panel does each test_id belong to (first wins).
        $testPanel = [];
        foreach ($panels as $p) {
            if (!isset($testPanel[$p['test_id']])) {
                $testPanel[$p['test_id']] = $p;
            }
        }

        // Panel list per section, preserving order.
        $panelsBySection = [];
        foreach ($panels as $p) {
            $sec = $testSection[$p['test_id']] ?? null;
            if ($sec === null) {
                continue;
            }
            $panelsBySection[$sec][$p['panel_id']] = $p;
        }

        foreach ($sections as $sectionName => $sectionTests) {
            $sectionId = $this->upsertGroup(
                $this->sectionCode($sectionName),
                $sectionName,
                0,       // top-level
                $labId,
                ++$sectionCount
            );

            $panelsHere = $panelsBySection[$sectionName] ?? [];

            // Panel groups nested in this section.
            $panelIds = [];
            $panelSeq = 0;
            foreach ($panelsHere as $panel) {
                $panelIds[$panel['panel_id']] = $this->upsertGroup(
                    'OEP-' . $panel['panel_id'],
                    $panel['panel_name'],
                    $sectionId,
                    $labId,
                    ++$panelSeq
                );
                $panelCount++;
            }

            // Tests: hang from their panel when they have one, else from the section.
            $ordSeq = 0;
            foreach ($sectionTests as $t) {
                $parentId = $sectionId;
                $panel = $testPanel[$t['test_id']] ?? null;
                if ($panel !== null) {
                    $parentId = $panelIds[$panel['panel_id']] ?? $sectionId;
                    if ($parentId === $sectionId) {
                        $warnings[] = "test {$t['test_id']} ({$t['test_name']}) belongs to panel {$panel['panel_id']} "
                            . "declared in another section; attached to section " . $sectionName;
                    }
                }

                $this->upsertTest(
                    'OE-' . $t['test_id'],
                    $t['test_name'],
                    $t['loinc'],
                    $parentId,
                    $labId,
                    ++$ordSeq
                );
                $testCount++;

                // Convenience mapping so the imported code is immediately sendable.
                if ($this->upsertMapping('OE-' . $t['test_id'], $t['test_name'], $t['test_id'])) {
                    $mappingCount++;
                }
            }
        }

        return [
            'sections' => $sectionCount,
            'panels' => $panelCount,
            'tests' => $testCount,
            'mappings' => $mappingCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Insert or update a group (grp) row and return its procedure_type_id.
     */
    private function upsertGroup(string $code, string $name, int $parent, int $labId, int $seq): int
    {
        $id = $this->lookupIdByCode($code);

        if ($id > 0) {
            sqlStatement(
                "UPDATE procedure_type SET name = ?, parent = ?, lab_id = ?, activity = 1, seq = ?
                 WHERE procedure_type_id = ?",
                [$name, $parent, $labId, $seq, $id]
            );
            return $id;
        }

        sqlStatement(
            "INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, activity, seq)
             VALUES (?, ?, ?, ?, 'grp', 1, ?)",
            [$parent, $name, $labId, $code, $seq]
        );

        // Re-read the generated id (procedure_code is unique per lab).
        return $this->lookupIdByCode($code);
    }

    /**
     * Insert or update an orderable test (ord) row.
     */
    private function upsertTest(string $code, string $name, string $loinc, int $parent, int $labId, int $seq): void
    {
        $id = $this->lookupIdByCode($code);

        $standardCode = $loinc !== '' ? 'LOINC:' . $loinc : '';

        if ($id > 0) {
            sqlStatement(
                "UPDATE procedure_type SET name = ?, parent = ?, lab_id = ?, standard_code = ?, activity = 1, seq = ?
                 WHERE procedure_type_id = ?",
                [$name, $parent, $labId, $standardCode, $seq, $id]
            );
            return;
        }

        sqlStatement(
            "INSERT INTO procedure_type (parent, name, lab_id, procedure_code, standard_code, procedure_type, activity, seq)
             VALUES (?, ?, ?, ?, ?, 'ord', 1, ?)",
            [$parent, $name, $labId, $code, $standardCode, $seq]
        );
    }

    /**
     * Find the procedure_type_id for a given procedure_code, or 0 if absent.
     */
    private function lookupIdByCode(string $code): int
    {
        $row = sqlQuery(
            "SELECT procedure_type_id AS id FROM procedure_type WHERE procedure_code = ? LIMIT 1",
            [$code]
        );
        return $row ? (int)$row['id'] : 0;
    }

    /**
     * Upsert a code-mapping row (procedure_code -> openelis_test_id).
     *
     * @return bool  True if a new mapping was inserted (false on update/exists).
     */
    private function upsertMapping(string $procedureCode, string $procedureName, string $testId): bool
    {
        $existing = sqlQuery(
            "SELECT id FROM mod_openelis_code_mapping WHERE openemr_procedure_code = ?",
            [$procedureCode]
        );
        if ($existing) {
            sqlStatement(
                "UPDATE mod_openelis_code_mapping
                 SET openemr_procedure_name = ?, openelis_test_id = ?, is_active = 1
                 WHERE openemr_procedure_code = ?",
                [$procedureName, $testId, $procedureCode]
            );
            return false;
        }

        sqlStatement(
            "INSERT INTO mod_openelis_code_mapping
                (openemr_procedure_code, openemr_procedure_name, openelis_test_id, is_active)
             VALUES (?, ?, ?, 1)",
            [$procedureCode, $procedureName, $testId]
        );
        return true;
    }

    /**
     * Read and validate catalog.csv.
     *
     * @return array  Rows keyed by header: test_id, test_name, loinc, section_name, is_active
     * @throws \RuntimeException
     */
    private function readCatalog(): array
    {
        $rows = $this->readCsv(self::CATALOG_FILE);
        $out = [];
        foreach ($rows as $row) {
            $testId = trim((string)($row['test_id'] ?? ''));
            $name = trim((string)($row['test_name'] ?? ''));
            $section = trim((string)($row['section_name'] ?? ''));
            if ($testId === '' || $name === '' || $section === '') {
                continue;
            }
            $out[] = [
                'test_id' => $testId,
                'test_name' => $name,
                'loinc' => trim((string)($row['loinc'] ?? '')),
                'section_name' => $section,
                'is_active' => strtoupper(trim((string)($row['is_active'] ?? ''))),
            ];
        }
        return $out;
    }

    /**
     * Read and validate panels.csv.
     *
     * @return array  Rows keyed by header: panel_id, panel_name, test_id
     * @throws \RuntimeException
     */
    private function readPanels(): array
    {
        $rows = $this->readCsv(self::PANELS_FILE);
        $out = [];
        foreach ($rows as $row) {
            $panelId = trim((string)($row['panel_id'] ?? ''));
            $panelName = trim((string)($row['panel_name'] ?? ''));
            $testId = trim((string)($row['test_id'] ?? ''));
            if ($panelId === '' || $testId === '') {
                continue;
            }
            $out[] = [
                'panel_id' => $panelId,
                'panel_name' => $panelName !== '' ? $panelName : ('Panel ' . $panelId),
                'test_id' => $testId,
            ];
        }
        return $out;
    }

    /**
     * Parse a CSV file into associative arrays, handling the BOM and quoted
     * empty fields such as `""`.
     *
     * @return array
     * @throws \RuntimeException
     */
    private function readCsv(string $file): array
    {
        $path = $this->csvDir . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                "Catalog file not found or not readable: $path. "
                . "Upload catalog.csv and panels.csv to the module's public directory."
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Could not read catalog file: $path");
        }

        // Strip UTF-8 BOM if present.
        if (strncmp($contents, "\xEF\xBB\xBF", 3) === 0) {
            $contents = substr($contents, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents);
        if ($lines === false) {
            $lines = [];
        }
        // Drop a trailing empty line.
        if (end($lines) === '') {
            array_pop($lines);
        }
        if (empty($lines)) {
            throw new \RuntimeException("Catalog file is empty: $path");
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map(static fn($h) => trim((string)$h), $header);

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line);
            if (count($cells) === 1 && ($cells[0] === null || $cells[0] === '')) {
                continue; // blank line
            }
            $row = [];
            foreach ($header as $i => $col) {
                if ($col === '') {
                    continue;
                }
                $row[$col] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Build a stable, readable, unique code for a section group.
     */
    private function sectionCode(string $sectionName): string
    {
        $slug = strtolower(trim($sectionName));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'section';
        }
        return 'OES-' . $slug;
    }
}
