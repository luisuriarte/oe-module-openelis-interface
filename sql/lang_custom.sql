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

-- Code finder fields (assign form)
('Spanish (Latin American)', 'el', 'LOINC Code',                                              'Código LOINC'),
('Spanish (Latin American)', 'el', 'SNOMED Specimen',                                         'Espécimen SNOMED'),
('Spanish (Latin American)', 'el', 'SNOMED Finding',                                          'Hallazgo SNOMED'),
('Spanish (Latin American)', 'el', 'Units',                                                   'Unidades'),
('Spanish (Latin American)', 'el', 'e.g., 2345-7',                                            'ej.: 2345-7'),
('Spanish (Latin American)', 'el', 'e.g., 119297000',                                         'ej.: 119297000'),
('Spanish (Latin American)', 'el', 'e.g., 33747003',                                          'ej.: 33747003'),
('Spanish (Latin American)', 'el', 'e.g., mg/dL',                                             'ej.: mg/dL'),
('Spanish (Latin American)', 'el', 'Search LOINC codes',                                      'Buscar códigos LOINC'),
('Spanish (Latin American)', 'el', 'Search SNOMED codes',                                     'Buscar códigos SNOMED'),

-- Configured mappings section
('Spanish (Latin American)', 'el', 'Configured Mappings',                                     'Mapeos Configurados'),
('Spanish (Latin American)', 'el', 'No configured mappings found.',                           'No hay mapeos configurados.'),

-- Table headers (mapped)
('Spanish (Latin American)', 'el', 'EMR Code',                                                'Código EMR'),
('Spanish (Latin American)', 'el', 'EMR Name',                                                'Nombre EMR'),
('Spanish (Latin American)', 'el', 'ELIS ID',                                                 'ID ELIS'),
('Spanish (Latin American)', 'el', 'ELIS Name',                                               'Nombre ELIS'),
('Spanish (Latin American)', 'el', 'LOINC',                                                   'LOINC'),
('Spanish (Latin American)', 'el', 'SNOMED',                                                  'SNOMED'),
('Spanish (Latin American)', 'el', 'Units',                                                   'Unidades'),
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
('Spanish (Latin American)', 'el', 'Next',                                                    'Siguiente'),

-- src/Bootstrap.php (menu labels)
('Spanish (Latin American)', 'el', 'Pending Orders',                                          'Órdenes Pendientes'),

-- public/pending_orders.php (page)
('Spanish (Latin American)', 'el', 'OpenELIS Pending Orders',                                 'Órdenes Pendientes OpenELIS'),
('Spanish (Latin American)', 'el', 'Send lab orders to OpenELIS on demand. Only orders with complete code mappings can be sent.', 'Envíe órdenes de laboratorio a OpenELIS bajo demanda. Solo las órdenes con mapeo completo de códigos pueden enviarse.'),
('Spanish (Latin American)', 'el', 'Pending',                                                 'Pendientes'),
('Spanish (Latin American)', 'el', 'All Orders',                                              'Todas las Órdenes'),
('Spanish (Latin American)', 'el', 'Sent',                                                    'Enviadas'),
('Spanish (Latin American)', 'el', 'Errors',                                                  'Errores'),
('Spanish (Latin American)', 'el', 'Search by patient name, ID, or order #...',               'Buscar por nombre de paciente, ID u orden #...'),
('Spanish (Latin American)', 'el', 'No pending orders to send.',                              'No hay órdenes pendientes para enviar.'),
('Spanish (Latin American)', 'el', 'No orders found.',                                        'No se encontraron órdenes.'),
('Spanish (Latin American)', 'el', 'Order #',                                                 'Orden #'),
('Spanish (Latin American)', 'el', 'Date',                                                    'Fecha'),
('Spanish (Latin American)', 'el', 'Patient',                                                 'Paciente'),
('Spanish (Latin American)', 'el', 'Lab',                                                     'Laboratorio'),
('Spanish (Latin American)', 'el', 'Tests',                                                   'Pruebas'),
('Spanish (Latin American)', 'el', 'Mapping',                                                 'Mapeo'),
('Spanish (Latin American)', 'el', 'Complete',                                                'Completo'),
('Spanish (Latin American)', 'el', 'None',                                                    'Ninguno'),
('Spanish (Latin American)', 'el', 'Pending',                                                 'Pendiente'),
('Spanish (Latin American)', 'el', 'Action',                                                  'Acción'),
('Spanish (Latin American)', 'el', 'Send',                                                    'Enviar'),
('Spanish (Latin American)', 'el', 'Sent',                                                    'Enviado'),
('Spanish (Latin American)', 'el', 'Error',                                                   'Error'),
('Spanish (Latin American)', 'el', 'Transmitted (HL7)',                                       'Transmitido (HL7)'),
('Spanish (Latin American)', 'el', 'Complete code mapping required',                          'Se requiere mapeo completo de códigos'),
('Spanish (Latin American)', 'el', 'Send this order to OpenELIS?',                            '¿Enviar esta orden a OpenELIS?'),
('Spanish (Latin American)', 'el', 'Sending...',                                              'Enviando...'),
('Spanish (Latin American)', 'el', 'Sent to OpenELIS',                                        'Enviado a OpenELIS'),
('Spanish (Latin American)', 'el', 'Error:',                                                  'Error:'),
('Spanish (Latin American)', 'el', 'Connection error',                                        'Error de conexión'),
('Spanish (Latin American)', 'el', 'Showing',                                                 'Mostrando'),
('Spanish (Latin American)', 'el', 'orders',                                                  'órdenes'),
('Spanish (Latin American)', 'el', 'Retry OpenELIS',                                          'Reintentar OpenELIS'),
('Spanish (Latin American)', 'el', 'Retry sending to OpenELIS',                              'Reintentar envío a OpenELIS'),
('Spanish (Latin American)', 'el', 'Send this order to OpenELIS via FHIR',                   'Enviar esta orden a OpenELIS vía FHIR'),

-- src/Service/OrderSyncService.php (status messages)
('Spanish (Latin American)', 'el', 'Order not found',                                         'Orden no encontrada'),
('Spanish (Latin American)', 'el', 'No tests to send',                                       'No hay pruebas para enviar'),
('Spanish (Latin American)', 'el', 'No active lab provider configured for this order',        'No hay proveedor de laboratorio activo configurado para esta orden'),
('Spanish (Latin American)', 'el', 'None of the tests have code mappings configured. Please configure code mappings first.', 'Ninguna de las pruebas tiene mapeo de códigos configurado. Configure el mapeo de códigos primero.'),
('Spanish (Latin American)', 'el', 'Skipped',                                                 'Omitidas'),
('Spanish (Latin American)', 'el', 'Order sent to OpenELIS successfully',                     'Orden enviada a OpenELIS correctamente'),
('Spanish (Latin American)', 'el', 'Error communicating with OpenELIS',                       'Error de comunicación con OpenELIS'),
('Spanish (Latin American)', 'el', 'Unexpected error',                                        'Error inesperado'),
('Spanish (Latin American)', 'el', 'Sync error — retry',                                      'Error de sincronización — reintentar'),
('Spanish (Latin American)', 'el', 'Access denied',                                           'Acceso denegado'),
('Spanish (Latin American)', 'el', 'Invalid CSRF token',                                      'Token CSRF inválido'),
('Spanish (Latin American)', 'el', 'Invalid order ID',                                        'ID de orden inválido'),
('Spanish (Latin American)', 'el', 'Lab provider is inactive',                                'El proveedor de laboratorio está inactivo'),
('Spanish (Latin American)', 'el', 'Internal error',                                          'Error interno');

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
