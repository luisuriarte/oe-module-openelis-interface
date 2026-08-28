#IfNotTable mod_openelis_code_mapping
-- =============================================================================
-- mod_openelis_code_mapping
-- =============================================================================
-- PURPOSE
--   Maps OpenEMR lab procedures to their equivalent test identifiers in
--   OpenELIS Global 2. When an order is transmitted from OpenEMR to OpenELIS
--   via FHIR (see src/Service/OrderSyncService.php and public/send_order_action.php),
--   each test in the order (procedure_order_code) is resolved through this table
--   to obtain the OpenELIS test ID and the FHIR codings used to build the
--   ServiceRequest resource (see src/Mappers/OrderMapper.php).
--
-- KEY CONCEPTS
--   * OpenEMR side: the procedure code and its human-readable name come from
--     the OpenEMR procedure catalog (procedure_type table).
--   * OpenELIS side: openelis_test_id maps directly to the numeric id of a test
--     in OpenELIS. The optional standardization columns (LOINC, SNOMED, units)
--     provide richer FHIR codings for the ServiceRequest and are recommended but
--     not mandatory for a basic transmission.
--   * Only rows with is_active = 1 AND a non-empty loinc_code are considered
--     "fully mapable" by the UI (see pending_orders.php mapping-completeness badge).
--     The send flow itself only requires a non-empty openelis_test_id.
--   * Maintenance: managed via the admin UI at admin_mapping.php (the "OpenELIS
--     Code Mapping" page under the Lab menu, ACL admin/super).
--
-- NOTE ON DEFAULTS
--   The UNIQUE KEY on openemr_procedure_code guarantees one mapping per OpenEMR
--   procedure. If a procedure is re-mapped, update in place (or toggle active)
--   rather than inserting a duplicate.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `mod_openelis_code_mapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT
      COMMENT 'Internal primary key for this mapping row',
  `openemr_procedure_code` varchar(50) NOT NULL
      COMMENT 'The procedure code in OpenEMR (procedure_type.procedure_code) that this mapping applies to',
  `openemr_procedure_name` varchar(255) NOT NULL
      COMMENT 'Human-readable name of the OpenEMR procedure (procedure_type.name), shown for reference in the UI',
  `openelis_test_id` varchar(50) NOT NULL
      COMMENT 'The test identifier in OpenELIS (clinlims.test.id). REQUIRED: the send flow will skip any test without this',
  `openelis_test_name` varchar(255) DEFAULT NULL
      COMMENT 'Human-readable name of the test as it appears in OpenELIS, shown for reference in the UI',
  `is_active` tinyint(1) DEFAULT 1
      COMMENT '1=active mapping is used, 0=inactive (ignored by the send flow). Use to disable a mapping without deleting it',
  `loinc_code` varchar(20) DEFAULT NULL
      COMMENT 'LOINC code for this test (http://loinc.org). Optional, recommended: adds a LOINC coding to the FHIR ServiceRequest and is used for the "fully mapped" badge in the UI',
  `snomed_specimen` varchar(20) DEFAULT NULL
      COMMENT 'SNOMED-CT code for the specimen type collected, emitted in the FHIR ServiceRequest specimen type',
  `snomed_finding` varchar(20) DEFAULT NULL
      COMMENT 'SNOMED-CT code for the finding/phenomenon being measured, emitted in the FHIR ServiceRequest code',
  `units` varchar(30) DEFAULT NULL
      COMMENT 'Expected measurement units (e.g. mg/dL) attached to the LOINC coding of the ServiceRequest',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mapping` (`openemr_procedure_code`),
  KEY `idx_loinc` (`loinc_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

#IfMissingColumn procedure_order mod_openelis_sync_status
ALTER TABLE `procedure_order` ADD COLUMN `mod_openelis_sync_status`
    ENUM('pending','sent','error') DEFAULT NULL
    COMMENT 'OpenELIS sync: pending=sent, error=failed, NULL=not yet attempted';
#EndIf

#IfMissingColumn procedure_order mod_openelis_order_id
ALTER TABLE `procedure_order` ADD COLUMN `mod_openelis_order_id` VARCHAR(64) DEFAULT NULL
    COMMENT 'OpenELIS ServiceRequest ID returned after order transmission';
#EndIf

#IfNotTable mod_openelis_config
-- =============================================================================
-- mod_openelis_config
-- =============================================================================
-- PURPOSE
--   Key/value store for module configuration, editable via the "OpenELIS
--   Settings" page (public/openelis_config.php). Keys used by the test-catalog
--   synchronizer (src/Catalog/OpenElisCatalog.php):
--     catalog_min_id, catalog_max_id
--       The inclusive numeric id range probed against the OpenELIS REST
--       endpoint /OpenELIS-Global/rest/TestNamesProvider?testId={id}. That
--       endpoint only accepts a single integer id (testId=all returns 500), so
--       the catalog is built by iterating this range. Defaults to 1..500.
--     api_user, api_pass
--       Optional API user/password for the catalog REST endpoint. If left
--       blank, the synchronizer falls back to the lab provider credentials in
--       procedure_providers (login / password / protocol = 'WS'), which the
--       send flow (FHIR) also uses. Storing them here lets the admin use a
--       dedicated API credential that may differ from the FHIR one, without
--       opening PostgreSQL access.
--
--   Why REST and not direct SQL:
--     The OpenELIS FHIR API (HAPI) on this instance does not expose a
--     test-catalog resource (ObservationDefinition is unknown; Observation and
--     ServiceRequest are for results/orders only). The standalone REST endpoint
--     /rest/TestNamesProvider?testId={id} is the supported way to read a test's
--     name (Spanish/English) by its numeric id. It does NOT return LOINC, so
--     LOINC must be entered manually in the mapping (optional).
--
--   SECURITY NOTE
--     api_pass is stored plaintext in OpenEMR's database, consistent with how
--     OpenEMR already stores procedure provider passwords. It is never logged
--     and never sent to the browser; the password field is shown blank with a
--     "keep existing" behavior on save.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `mod_openelis_config` (
  `cfg_name` varchar(64) NOT NULL
      COMMENT 'Configuration key name (e.g. catalog_min_id, catalog_max_id, api_user, api_pass)',
  `cfg_value` varchar(255) DEFAULT NULL
      COMMENT 'Configuration value (plaintext; see security note in the header of this CREATE statement)',
  PRIMARY KEY (`cfg_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

#IfNotTable mod_openelis_test_catalog
-- =============================================================================
-- mod_openelis_test_catalog
-- =============================================================================
-- PURPOSE
--   Local mirror of the OpenELIS test catalog (numeric test id -> display name),
--   populated by src/Catalog/OpenElisCatalog.php synchronizing
--   via the REST endpoint /OpenELIS-Global/rest/TestNamesProvider?testId={id}.
--
--   The mapping page (public/admin_mapping.php) reads this table for
--   autosuggestion (datalist) when assigning openelis_test_id / name to an
--   OpenEMR procedure, without re-hitting the remote API on every keystroke.
--
--   This REST endpoint returns only the test NAME (Spanish/English) — it does
--   NOT provide a LOINC code, so LOINC is not stored here and must be entered
--   manually in the mapping (optional).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `mod_openelis_test_catalog` (
  `openelis_test_id` varchar(20) NOT NULL
      COMMENT 'OpenELIS numeric test id (clinlims.test.id)',
  `name_es` varchar(255) DEFAULT NULL
      COMMENT 'Spanish test name, as returned by TestNamesProvider (name.spanish)',
  `name_en` varchar(255) DEFAULT NULL
      COMMENT 'English test name, as returned by TestNamesProvider (name.english)',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP
      COMMENT 'Last time this catalog row was refreshed by the synchronizer',
  PRIMARY KEY (`openelis_test_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Local mirror of the OpenELIS test catalog for mapping autosuggestion';
#EndIf