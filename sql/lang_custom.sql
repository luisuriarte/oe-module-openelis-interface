-- ---------------------------------------------------------------
-- Language Custom Translations for oe-module-openelis
-- Spanish (Latin American) / lang_code = el
-- ---------------------------------------------------------------

START TRANSACTION;

INSERT IGNORE INTO `lang_custom` (`lang_description`, `lang_code`, `constant_name`, `definition`) VALUES

-- src/Bootstrap.php (OpenELIS parent menu + children labels)
('Spanish (Latin American)', 'el', 'OpenELIS',                                                       'OpenELIS'),
('Spanish (Latin American)', 'el', 'Code Mapping',                                                    'Mapeo de Códigos'),
('Spanish (Latin American)', 'el', 'Settings',                                                        'Configuración'),

-- public/admin_mapping.php (page title / header)
('Spanish (Latin American)', 'el', 'Access denied',                                          'Acceso denegado'),
('Spanish (Latin American)', 'el', 'OpenELIS Code Mapping',                                  'Mapeo de Códigos OpenELIS'),
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
('Spanish (Latin American)', 'el', 'Lab provider protocol must be set to Web Services (WS) to send orders via OpenELIS', 'El protocolo del proveedor de laboratorio debe configurarse como Web Services (WS) para enviar órdenes vía OpenELIS'),
('Spanish (Latin American)', 'el', 'Internal error',                                          'Error interno'),

-- public/openelis_config.php (settings page)
('Spanish (Latin American)', 'el', 'OpenELIS Settings',                                        'Configuración OpenELIS'),
('Spanish (Latin American)', 'el', 'Catalog of OpenELIS tests, read over the REST API. You can enter a dedicated API user/password here, or leave it blank to reuse the lab provider credentials (procedure_providers, protocol = WS). No database credentials are needed.', 'Catálogo de pruebas de OpenELIS leído mediante la API REST. Puede ingresar aquí un usuario/contraseña de API dedicado, o dejarlo en blanco para reutilizar las credenciales del proveedor de laboratorio (procedure_providers, protocol = WS). No se requieren credenciales de base de datos.'),
('Spanish (Latin American)', 'el', 'Synchronize test catalog',                                 'Sincronizar catálogo de pruebas'),
('Spanish (Latin American)', 'el', 'Local mirror',                                             'Espejo local'),
('Spanish (Latin American)', 'el', 'last sync',                                                'última sincronización'),
('Spanish (Latin American)', 'el', 'Sync now',                                                 'Sincronizar ahora'),
('Spanish (Latin American)', 'el', 'tests stored',                                             'pruebas almacenadas'),
('Spanish (Latin American)', 'el', 'Stopped after a run of inactive ids (probed up to',        'Se detuvo tras una serie de id inactivos (se probó hasta'),
('Spanish (Latin American)', 'el', 'Catalog sync failed',                                      'Error al sincronizar el catálogo'),
('Spanish (Latin American)', 'el', 'OpenELIS API credentials',                                 'Credenciales de la API OpenELIS'),
('Spanish (Latin American)', 'el', 'User/password for the test-catalog REST endpoint. Leave the user blank to fall back to the lab provider credentials (procedure_providers, protocol = WS).', 'Usuario/contraseña para el endpoint REST del catálogo de pruebas. Deje el usuario en blanco para reutilizar las credenciales del proveedor de laboratorio (procedure_providers, protocol = WS).'),
('Spanish (Latin American)', 'el', 'API user',                                                 'Usuario de API'),
('Spanish (Latin American)', 'el', 'API password',                                             'Contraseña de API'),
('Spanish (Latin American)', 'el', 'A password is stored. Leave blank to keep it.',            'Hay una contraseña almacenada. Déjela en blanco para conservarla.'),
('Spanish (Latin American)', 'el', 'Optional - falls back to provider credentials.',           'Opcional: reutiliza las credenciales del proveedor.'),
('Spanish (Latin American)', 'el', 'Id probing range',                                         'Rango de sondeo de id'),
('Spanish (Latin American)', 'el', 'The endpoint /OpenELIS-Global/rest/TestNamesProvider?testId={id} only accepts one integer id at a time (testId=all fails), so the catalog is built by iterating this range. Increase the max if new tests are added beyond it.', 'El endpoint /OpenELIS-Global/rest/TestNamesProvider?testId={id} solo acepta un id entero a la vez (testId=all falla), por lo que el catálogo se construye iterando este rango. Aumente el máximo si se agregan pruebas más allá de él.'),
('Spanish (Latin American)', 'el', 'From id',                                                  'Desde id'),
('Spanish (Latin American)', 'el', 'To id',                                                    'Hasta id'),
('Spanish (Latin American)', 'el', 'Save Configuration',                                       'Guardar Configuración'),
('Spanish (Latin American)', 'el', 'Go to Code Mapping',                                       'Ir al Mapeo de Códigos'),
('Spanish (Latin American)', 'el', 'Configuration saved.',                                     'Configuración guardada.'),

-- Import catalog into lab procedures (CSV)
('Spanish (Latin American)', 'el', 'Import catalog into lab procedures',                       'Importar catálogo en procedimientos de laboratorio'),
('Spanish (Latin American)', 'el', 'Reads catalog.csv and panels.csv from this module\'s public folder and creates the lab procedure tree (groups + orderable tests) in the OpenEMR procedure catalog, tagged with the selected lab provider.', 'Lee catalog.csv y panels.csv de la carpeta public de este módulo y crea el árbol de procedimientos de laboratorio (grupos + pruebas ordenables) en el catálogo de procedimientos de OpenEMR, identificadas con el proveedor de laboratorio seleccionado.'),
('Spanish (Latin American)', 'el', 'Lab provider (lab_id)',                                    'Proveedor de laboratorio (lab_id)'),
('Spanish (Latin American)', 'el', 'Select a provider...',                                     'Seleccione un proveedor...'),
('Spanish (Latin American)', 'el', 'CSV files',                                                'Archivos CSV'),
('Spanish (Latin American)', 'el', 'Import CSV',                                               'Importar CSV'),
('Spanish (Latin American)', 'el', 'No active lab providers found. Configure one in the lab providers section first.', 'No se encontraron proveedores de laboratorio activos. Configure uno en la sección de proveedores de laboratorio primero.'),
('Spanish (Latin American)', 'el', 'Select a lab provider to import the catalog for.',         'Seleccione un proveedor de laboratorio para importar el catálogo.'),
('Spanish (Latin American)', 'el', 'Catalog imported',                                         'Catálogo importado'),
('Spanish (Latin American)', 'el', 'groups',                                                   'grupos'),
('Spanish (Latin American)', 'el', 'panels',                                                   'paneles'),
('Spanish (Latin American)', 'el', 'tests',                                                    'pruebas'),
('Spanish (Latin American)', 'el', 'mappings',                                                 'mapeos'),
('Spanish (Latin American)', 'el', 'Warnings',                                                 'Advertencias'),
('Spanish (Latin American)', 'el', 'Catalog import failed',                                    'Error al importar el catálogo'),

-- public/catalog_import.php (bulk catalog import)
('Spanish (Latin American)', 'el', 'Import Catalog',                                           'Importar Catálogo'),
('Spanish (Latin American)', 'el', 'Imports the OpenELIS test catalog (panels + ordered tests) into the OpenEMR lab procedure catalog, respecting each provider\'s own catalog. Tests with catalog errors are excluded; warnings are reported. Manual code mappings are never overwritten.', 'Importa el catálogo de pruebas de OpenELIS (paneles + pruebas ordenables) al catálogo de procedimientos de laboratorio de OpenEMR, respetando el catálogo propio de cada proveedor. Las pruebas con errores de catálogo se excluyen; las advertencias se informan. Los mapeos de códigos manuales nunca se sobrescriben.'),
('Spanish (Latin American)', 'el', 'Lab provider',                                              'Proveedor de laboratorio'),
('Spanish (Latin American)', 'el', 'Select a lab provider...',                                  'Seleccione un proveedor de laboratorio...'),
('Spanish (Latin American)', 'el', 'Preview',                                                   'Vista previa'),
('Spanish (Latin American)', 'el', 'Confirm import',                                            'Confirmar importación'),
-- patched core form interface/orders/procedure_provider_edit.php
-- (OpenELIS catalog credentials section, see patches/procedure_provider_edit.php)
('Spanish (Latin American)', 'el', 'OpenELIS Catalog Login',                                      'Usuario de catálogo OpenELIS'),
('Spanish (Latin American)', 'el', 'OpenELIS ADMIN user',                                         'Usuario ADMIN de OpenELIS'),
('Spanish (Latin American)', 'el', 'Leave blank to keep the stored password',                     'Dejar en blanco para conservar la contraseña almacenada'),
('Spanish (Latin American)', 'el', 'OpenELIS ADMIN user for the REST test-catalog API - different from the operational user used to send orders (Login/Password above).', 'Usuario con rol ADMIN de OpenELIS para la API REST test-catalog: es distinto del usuario operativo usado para enviar órdenes (Login/Password de más arriba).'),
('Spanish (Latin American)', 'el', 'This second credential set is used by the module\'s bulk catalog import (catalog_import.php). Assign the ADMIN role to this OpenELIS user in the OpenELIS admin UI.', 'Este segundo par de credenciales lo usa la importación masiva de catálogo del módulo (catalog_import.php). Asigne el rol ADMIN a este usuario de OpenELIS en la interfaz de administración de OpenELIS.'),
('Spanish (Latin American)', 'el', 'The password is never sent back to the browser: leave the field blank to keep the stored value.', 'La contraseña nunca vuelve al navegador: deje el campo en blanco para conservar el valor almacenado.'),
('Spanish (Latin American)', 'el', 'Go to Settings',                                            'Ir a Configuración'),
('Spanish (Latin American)', 'el', 'Select a valid lab provider.',                              'Seleccione un proveedor de laboratorio válido.');

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
