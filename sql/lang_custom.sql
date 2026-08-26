-- ---------------------------------------------------------------
-- Language Custom Translations for oe-module-openelis
-- Spanish (Latin American) / lang_code = el
-- ---------------------------------------------------------------

START TRANSACTION;

INSERT IGNORE INTO `lang_custom` (`lang_description`, `lang_code`, `constant_name`, `definition`) VALUES

-- src/Bootstrap.php (menu label)
('Spanish (Latin American)', 'el', 'OpenELIS Code Mapping',                                  'Mapeo de Códigos OpenELIS'),

-- public/admin_mapping.php (page title / header)
('Spanish (Latin American)', 'el', 'Access denied',                                          'Acceso denegado'),
('Spanish (Latin American)', 'el', 'OpenEMR to OpenELIS Code Mapping',                       'Mapeo de Códigos OpenEMR a OpenELIS'),
('Spanish (Latin American)', 'el', 'Map OpenEMR lab procedures to OpenELIS tests.',           'Asocie los procedimientos de laboratorio de OpenEMR con las pruebas de OpenELIS.'),

-- Alerts
('Spanish (Latin American)', 'el', 'Mapping saved successfully.',                             'Mapeo guardado correctamente.'),
('Spanish (Latin American)', 'el', 'Mapping status updated.',                                 'Estado del mapeo actualizado.'),

-- Search
('Spanish (Latin American)', 'el', 'Search by name, code, or standard...',                   'Buscar por nombre, código o estándar...'),
('Spanish (Latin American)', 'el', 'Search',                                                  'Buscar'),
('Spanish (Latin American)', 'el', 'Clear',                                                   'Limpiar'),

-- Unmapped section
('Spanish (Latin American)', 'el', 'Unmapped Procedures',                                    'Procedimientos Sin Mapeo'),
('Spanish (Latin American)', 'el', 'No unmapped procedures found.',                           'No hay procedimientos sin mapeo.'),

-- Table headers (unmapped)
('Spanish (Latin American)', 'el', 'Code',                                                    'Código'),
('Spanish (Latin American)', 'el', 'Name',                                                    'Nombre'),
('Spanish (Latin American)', 'el', 'Standard',                                                'Estándar'),
('Spanish (Latin American)', 'el', 'Action',                                                  'Acción'),

-- Assign form
('Spanish (Latin American)', 'el', 'Assign',                                                  'Asignar'),
('Spanish (Latin American)', 'el', 'OpenELIS Test ID',                                        'ID de Prueba OpenELIS'),
('Spanish (Latin American)', 'el', 'e.g., 42',                                                'ej.: 42'),
('Spanish (Latin American)', 'el', 'OpenELIS Test Name',                                      'Nombre de Prueba OpenELIS'),
('Spanish (Latin American)', 'el', 'e.g., Glucose',                                           'ej.: Glucosa'),
('Spanish (Latin American)', 'el', 'Save',                                                    'Guardar'),
('Spanish (Latin American)', 'el', 'Cancel',                                                  'Cancelar'),

-- Configured mappings section
('Spanish (Latin American)', 'el', 'Configured Mappings',                                     'Mapeos Configurados'),
('Spanish (Latin American)', 'el', 'No configured mappings found.',                           'No hay mapeos configurados.'),

-- Table headers (mapped)
('Spanish (Latin American)', 'el', 'EMR Code',                                                'Código EMR'),
('Spanish (Latin American)', 'el', 'EMR Name',                                                'Nombre EMR'),
('Spanish (Latin American)', 'el', 'ELIS ID',                                                 'ID ELIS'),
('Spanish (Latin American)', 'el', 'ELIS Name',                                               'Nombre ELIS'),
('Spanish (Latin American)', 'el', 'Status',                                                  'Estado'),
('Spanish (Latin American)', 'el', 'Actions',                                                 'Acciones'),

-- Status badges
('Spanish (Latin American)', 'el', 'Active',                                                  'Activo'),
('Spanish (Latin American)', 'el', 'Inactive',                                                'Inactivo'),

-- Edit form / actions
('Spanish (Latin American)', 'el', 'Edit',                                                    'Editar'),
('Spanish (Latin American)', 'el', 'Are you sure you want to change the status?',             '¿Está seguro de cambiar el estado?'),
('Spanish (Latin American)', 'el', 'Deactivate',                                              'Desactivar'),
('Spanish (Latin American)', 'el', 'Activate',                                                'Activar'),

-- Pagination
('Spanish (Latin American)', 'el', 'Previous',                                                'Anterior'),
('Spanish (Latin American)', 'el', 'Next',                                                    'Siguiente');

-- ============================================================================
-- SYNC: Populate lang_languages, lang_constants and lang_definitions
-- Idempotent: safe to re-run, preserves existing entries.
--
-- IMPORTANTE: lang_constants.constant_name usa utf8mb4_bin (case-sensitive),
-- mientras que lang_custom.constant_name usa utf8mb3_general_ci.
-- Por eso usamos CONVERT(lc.constant_name USING utf8mb4) en los JOINs
-- para que MySQL compare usando el collation utf8mb4_bin de lang_constants.
-- ============================================================================

-- 2. Create language if it does not exist
INSERT INTO lang_languages (lang_code, lang_description)
SELECT DISTINCT lc.lang_code, lc.lang_description
FROM lang_custom lc
WHERE NOT EXISTS (
    SELECT 1 FROM lang_languages l WHERE l.lang_code = lc.lang_code
);

-- 3. Create new constants (case-sensitive comparison)
INSERT INTO lang_constants (constant_name)
SELECT DISTINCT lc.constant_name
FROM lang_custom lc
WHERE lc.constant_name <> ''
  AND NOT EXISTS (
    SELECT 1 FROM lang_constants c
    WHERE c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
  );

-- 4. Insert new definitions
INSERT INTO lang_definitions (cons_id, lang_id, definition)
SELECT c.cons_id, l.lang_id, lc.definition
FROM lang_custom lc
INNER JOIN lang_constants c
    ON c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
INNER JOIN lang_languages l
    ON l.lang_code = lc.lang_code
WHERE NOT EXISTS (
    SELECT 1 FROM lang_definitions d
    WHERE d.cons_id = c.cons_id AND d.lang_id = l.lang_id
);

-- 5. Update modified translations
UPDATE lang_definitions d
INNER JOIN lang_constants c ON c.cons_id = d.cons_id
INNER JOIN lang_languages l ON l.lang_id = d.lang_id
INNER JOIN lang_custom lc
    ON l.lang_code = lc.lang_code
    AND c.constant_name = CONVERT(lc.constant_name USING utf8mb4)
SET d.definition = lc.definition
WHERE IFNULL(d.definition, '') <> IFNULL(lc.definition, '');

COMMIT;
