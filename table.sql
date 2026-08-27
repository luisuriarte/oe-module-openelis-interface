#IfNotTable mod_openelis_code_mapping
CREATE TABLE IF NOT EXISTS `mod_openelis_code_mapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `openemr_procedure_code` varchar(50) NOT NULL,
  `openemr_procedure_name` varchar(255) NOT NULL,
  `openelis_test_id` varchar(50) NOT NULL,
  `openelis_test_name` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `loinc_code` varchar(20) DEFAULT NULL,
  `snomed_specimen` varchar(20) DEFAULT NULL,
  `snomed_finding` varchar(20) DEFAULT NULL,
  `units` varchar(30) DEFAULT NULL,
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