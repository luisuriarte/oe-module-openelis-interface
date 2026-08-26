#IfNotTable mod_openelis_code_mapping
CREATE TABLE IF NOT EXISTS `mod_openelis_code_mapping` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `openemr_procedure_code` VARCHAR(50) NOT NULL,
  `openemr_procedure_name` VARCHAR(255) NOT NULL,
  `openelis_test_id` VARCHAR(50) NOT NULL,
  `openelis_test_name` VARCHAR(255) NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  UNIQUE KEY `uk_mapping` (`openemr_procedure_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
#EndIf
