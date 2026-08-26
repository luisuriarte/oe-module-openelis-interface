#IfNotTable mod_openelis_code_mapping
CREATE TABLE IF NOT EXISTS `mod_openelis_code_mapping` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `openemr_procedure_code` VARCHAR(50) NOT NULL,
  `openemr_procedure_name` VARCHAR(255) NOT NULL,
  `openelis_test_id` VARCHAR(50) NOT NULL,
  `openelis_test_name` VARCHAR(255) NULL,
  `loinc_code` VARCHAR(20) DEFAULT NULL,
  `snomed_specimen` VARCHAR(20) DEFAULT NULL,
  `snomed_finding` VARCHAR(20) DEFAULT NULL,
  `units` VARCHAR(30) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  UNIQUE KEY `uk_mapping` (`openemr_procedure_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

#IfMissingColumn mod_openelis_code_mapping loinc_code
ALTER TABLE `mod_openelis_code_mapping` ADD COLUMN `loinc_code` VARCHAR(20) DEFAULT NULL;
#EndIf

#IfMissingColumn mod_openelis_code_mapping snomed_specimen
ALTER TABLE `mod_openelis_code_mapping` ADD COLUMN `snomed_specimen` VARCHAR(20) DEFAULT NULL;
#EndIf

#IfMissingColumn mod_openelis_code_mapping snomed_finding
ALTER TABLE `mod_openelis_code_mapping` ADD COLUMN `snomed_finding` VARCHAR(20) DEFAULT NULL;
#EndIf

#IfMissingColumn mod_openelis_code_mapping units
ALTER TABLE `mod_openelis_code_mapping` ADD COLUMN `units` VARCHAR(30) DEFAULT NULL;
#EndIf

#IfNotIndex mod_openelis_code_mapping idx_loinc
CREATE INDEX `idx_loinc` ON `mod_openelis_code_mapping` (`loinc_code`);
#EndIf
