# 🏥 OpenEMR ↔ OpenELIS Interface

[![License: GPL v3](https://img.shields.io/badge/Licencia-GPLv3-azul.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![OpenEMR](https://img.shields.io/badge/OpenEMR-8.0%2F8.2-verde.svg)](https://www.open-emr.org)
[![OpenELIS](https://img.shields.io/badge/OpenELIS-Global%202-naranja.svg)](https://github.com/DIGI-UW/OpenELIS-Global-2)

**Módulo custom para OpenEMR** que integra el sistema con [OpenELIS Global 2](https://github.com/DIGI-UW/OpenELIS-Global-2) para sincronizar órdenes de laboratorio, pacientes y resultados.

> 📍 **Ruta del módulo:** `interface/modules/custom_modules/openelis/`
>
> 🌐 **Instancias:** [hcd.origen.ar](http://hcd.origen.ar) (OpenEMR) ↔ [elis.origen.ar](http://elis.origen.ar) (OpenELIS)

---

## 📋 Índice

- [ Características](#-características)
- [ Arquitectura](#-arquitectura)
- [ Estructura de directorios](#-estructura-de-directorios)
- [ Despliegue](#-despliegue)
- [ Instalación](#-instalación)
- [ Mapeo de códigos](#-mapeo-de-códigos)
- [ Uso de la API](#-uso-de-la-api)
- [ Configuración](#-configuración)
- [ Desarrollo](#-desarrollo)
- [ Licencia](#-licencia)

---

## ✨ Características

| Característica | Estado | Descripción |
|----------------|--------|-------------|
| 🔀 Mapeo de códigos | ✅ Listo | Asocia códigos de procedimientos de OpenEMR con IDs de pruebas de OpenELIS |
| 🧪 Sincronización de órdenes | 🔜 Próximamente | Envía órdenes de laboratorio de OpenEMR a OpenELIS |
| 👤 Sincronización de pacientes | 🔜 Próximamente | Sincroniza demografía de pacientes |
| 📊 Obtención de resultados | 🔜 Próximamente | Recupera resultados de laboratorio vía webhook |
| 🔔 Notificación de resultados | 🔜 Próximamente | Notifica a pacientes y profesionales de resultados |

---

## 🏗 Arquitectura

```
┌─────────────────────────────────┐         ┌─────────────────────────────────┐
│          OpenEMR                │         │          OpenELIS               │
│      (hcd.origen.ar)            │  REST   │      (elis.origen.ar)           │
│                                 │◄───────►│                                 │
│  ┌───────────────────────────┐  │  HL7    │  ┌───────────────────────────┐  │
│  │   oe-module-openelis      │  │         │  │     OpenELIS Global 2     │  │
│  │                           │  │         │  │                           │  │
│  │  • Mapeo de códigos       │  │         │  │  • Catálogo de pruebas    │  │
│  │  • Sinc. órdenes (plan.)  │  │         │  │  • Gestión de pacientes   │  │
│  │  • Sinc. pacientes (plan.)│  │         │  │  • Gestión de órdenes     │  │
│  │  • Webhook de resultados  │  │         │  │  • Reporte de resultados  │  │
│  └───────────────────────────┘  │         │  └───────────────────────────┘  │
└─────────────────────────────────┘         └─────────────────────────────────┘
```

---

## 📁 Estructura de directorios

```
openelis/
├── 📄 table.sql                          # Migración SQL (directiva #IfNotTable)
├── 📄 version.php                        # Versión del módulo (1.0.0)
├── 📄 info.txt                           # Descripción del módulo
├── 📄 openemr.bootstrap.php              # Bootstrap: namespace + suscripción a eventos
├── 📄 ModuleManagerListener.php          # Ciclo de vida: instalar/habilitar/deshabilitar
│
├── 📂 src/
│   ├── 🔧 Bootstrap.php                  # Registro de menú + listeners de eventos
│   ├── 🔧 CodeMappingService.php         # Consultas reutilizables de mapeo de códigos
│   ├── 🔧 OrderSyncService.php           # Servicios syncPatient/syncPractitioner/sendOrder
│   ├── 📂 Client/                        # Clientes HTTP (FHIR + catálogo REST)
│   │   ├── 🔌 OpenElisApiClient.php      # Cliente FHIR R4 (flujo de envío de órdenes)
│   │   └── 🔌 CatalogApiClient.php       # Cliente REST test-catalog (usuario admin catálogo)
│   ├── 📂 Mappers/                       # Mapeadores FHIR Patient/Practitioner/Order
│   └── 📂 Service/                       # Servicios de negocio
│       ├── 🗄️ CatalogImportService.php   # Catálogo REST → procedure_type + mapeos (por proveedor)
│       └── 🗄️ ProcedureCatalogImporter.php # CSV legado → procedure_type (grp + ord)
│
├── 📂 public/                            # ⭐ Scripts web — se copian a <raiz_openemr>/public/modules/openelis/
│   ├── 🖥️ admin_mapping.php              # Interfaz admin para CRUD de mapeo de códigos
│   ├── 🖥️ pending_orders.php             # Órdenes pendientes + UI para enviar a OpenELIS
│   ├── 🖥️ send_order_action.php          # Endpoint AJAX (POST → JSON)
│   ├── 🖥️ catalog_import.php             # Importación masiva de catálogo (vista previa + confirmar)
│   ├── 🗂️ catalog.csv                    # Export del catálogo de pruebas de OpenELIS (import directo, legado)
│   └── 🗂️ panels.csv                     # Export de paneles de OpenELIS (import directo, legado)
│
├── 📂 sql/
│   └── 📄 lang_custom.sql                # Traducciones custom
│
├── 📂 patches/
│   └── 📄 common.php.patch.txt           # Parche opcional para botón "Enviar" en el form
│
├── 📄 README.md                          # Documentación (inglés)
└── 📄 README_es.md                       # Este archivo (español)
```

> ⭐ **IMPORTANTE:** Los archivos bajo `public/` son los únicos que el servidor
> web debe alcanzar directamente. El árbol `interface/` de OpenEMR está
> protegido y **no sirve scripts de módulos arbitrarios** por URL, así que en
> producción el contenido de `public/` se **copia** a la carpeta
> `public/modules/openelis/` de la raíz de OpenEMR. Ver
> [Despliegue](#-despliegue).

---

## 🚀 Despliegue

El árbol `interface/` de OpenEMR está protegido por el servidor web y **no sirve
scripts de módulos arbitrarios accesibles por URL**. Para que las páginas web y
los endpoints AJAX del módulo sean alcanzables, el contenido de `public/` debe
**copiarse** a la carpeta `public/modules/openelis/` de la raíz de OpenEMR.

> 💡 **¿Por qué no apuntar a la carpeta del módulo?** nginx (y la capa de
> seguridad de OpenEMR) devolverá 404 o redirigirá cualquier script del módulo
> pedido vía `interface/modules/custom_modules/...`. Los scripts "bootstrap"
> subiendo hasta encontrar `globals.php`, así que funcionan correctamente desde
> la carpeta `public/modules/openelis/` de la raíz.

### Pasos

1. Instalar el módulo (ver [Instalación](#-instalación)).
2. **Copiar los scripts web** a la carpeta `public/modules/openelis/` de la
   raíz de OpenEMR:

   ```bash
   mkdir -p /var/www/html/origen.ar/hcd/public/modules/openelis
   cp interface/modules/custom_modules/openelis/public/* \
      /var/www/html/origen.ar/hcd/public/modules/openelis/
   ```

3. **Verificar** que los endpoints respondan (sin 404). Probar el endpoint AJAX
   desde la consola del servidor:

   ```bash
   curl -s -X POST "https://hcd.origen.ar/public/modules/openelis/send_order_action.php" \
        -d "order_id=27" -d "action=send"
   ```

4. Tras actualizar la carpeta `public/` del módulo (scripts y/o exports CSV),
   volver a copiar su contenido a la raíz `public/modules/openelis/` para que la
   versión desplegada quede sincronizada.

### Mapa de URLs (producción)

| Script | URL en producción |
|--------|-------------------|
| `send_order_action.php` | `https://hcd.origen.ar/public/modules/openelis/send_order_action.php` |
| `pending_orders.php`    | `https://hcd.origen.ar/public/modules/openelis/pending_orders.php` |
| `admin_mapping.php`     | `https://hcd.origen.ar/public/modules/openelis/admin_mapping.php` |

> ⚠️ **Detección de raíz:** Los scripts ubican el `globals.php` de OpenEMR
> automáticamente subiendo desde su propia carpeta. Comprueban tanto
> `<dir>/globals.php` como `<dir>/interface/globals.php` en cada nivel, así
> funcionan ya sea que `globals.php` esté en la raíz de OpenEMR o (como en esta
> instalación) bajo la carpeta `interface/` de la raíz. Esto también funciona
> desde la carpeta del módulo (dev) o desde `public/modules/<nombre>/` (prod),
> por lo que no hace falta cambiar código entre entornos.

---

## 🚀 Instalación

### Requisitos previos

- ✅ OpenEMR 8.0 o 8.2
- ✅ Instancia de OpenELIS Global 2 funcionando
- ✅ PHP 8.1+
- ✅ MySQL/MariaDB con soporte InnoDB

### Pasos

1. **Copiar el módulo** a la instalación de OpenEMR:

   ```bash
   cp -r oe-module-openelis-interface/ \
         /ruta/a/openemr/interface/modules/custom_modules/openelis/
   ```

2. **Registrar el módulo** en OpenEMR:
   - Navegar a **Admin → Módulos** (Laminas Module Manager)
   - Hacer clic en **Registrar** y seleccionar el directorio `openelis`
   - El `table.sql` se ejecutará automáticamente, creando la tabla `mod_openelis_code_mapping`

3. **Habilitar el módulo:**
   - En el Module Manager, hacer clic en **Habilitar** en el módulo OpenELIS Interface
   - El elemento de menú "Mapeo códigos OpenELIS" aparecerá en la sección de Laboratorio

4. **Verificar la instalación:**

   ```sql
   SHOW TABLES LIKE 'mod_openelis_code_mapping';
   -- Debería devolver 1 fila
   ```

---

## 🔀 Mapeo de códigos

La capa de mapeo traduce los códigos de procedimientos de OpenEMR (`procedure_type.procedure_code`) a IDs de pruebas de OpenELIS (`test.id`).

### 📊 Tabla: `mod_openelis_code_mapping`

La tabla es **multi-laboratorio**: el mismo código de procedimiento de OpenEMR
puede pedirse contra laboratorios distintos, y cada uno lo resuelve a una prueba
OpenELIS distinta. La clave única es por lo tanto
`(openemr_procedure_code, provider_id)`; `provider_id` referencia
`procedure_providers.ppid` (0 = filas legadas / sin laboratorio asociado).

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | INT (PK) | Identificador autoincremental |
| `provider_id` | INT | `procedure_providers.ppid` al que pertenece el mapeo (0 = sin asignar) |
| `openemr_procedure_code` | VARCHAR(50) | Código de procedimiento de OpenEMR (único por proveedor) |
| `openemr_procedure_name` | VARCHAR(255) | Nombre para mostrar del procedimiento |
| `openelis_test_id` | VARCHAR(50) | ID de prueba en OpenELIS |
| `openelis_test_name` | VARCHAR(255) | Nombre de la prueba en OpenELIS |
| `openelis_panel_id` | VARCHAR(20) | Panel de OpenELIS del que se importó la prueba (informativo) |
| `openelis_panel_name` | VARCHAR(255) | Nombre del panel (informativo) |
| `is_active` | TINYINT(1) | 1 = activo, 0 = inactivo |
| `import_source` | ENUM(`'manual'`,`'catalog_import'`) | Las filas `manual` nunca se sobrescriben al importar |
| `imported_at` | DATETIME | Última vez que el importador tocó esta fila |
| `loinc_code` | VARCHAR(20) | Código LOINC (agrega coding FHIR; usado en el badge "totalmente mapeado") |
| `snomed_specimen` / `snomed_finding` / `units` | VARCHAR | Columnas opcionales de estandarización FHIR |

Comportamiento de colisión al importar:
- Las filas `import_source = 'catalog_import'` (generadas por el importador) se
  sobrescriben en su lugar al re-importar (idempotente).
- Las filas `import_source = 'manual'` (creadas a mano en `admin_mapping.php`)
  **nunca se sobrescriben**: un re-import solo refresca
  `openelis_panel_id` / `openelis_panel_name` / `imported_at` y reporta el
  mapeo como **conflicto** para revisión humana.

### 🧩 Importación de catálogo (REST) — `catalog_import.php`

La vía recomendada para armar el catálogo de procedimientos de un proveedor.
Lee la API REST de OpenELIS `GET /OpenELIS-Global/rest/test-catalog/*`
(requiere un usuario **ADMIN** de OpenELIS, configurado por proveedor en
`procedure_providers.mod_openelis_catalog_login` / `mod_openelis_catalog_password`
— nunca el usuario operativo Analyser Import) y:

1. Lista los paneles activos y, por panel, sus pruebas ordenables.
2. Cruza cada prueba contra la lista de pruebas activas
   (`errorCount` / `findings`):
   - `errorCount > 0` o cualquier finding de severidad ERROR → **excluida**
     (p. ej. una prueba huérfana sin vínculo de tipo de muestra,
     `SAMPLE_TYPE_LINKS`);
   - findings solo WARNING (p. ej. `DUPLICATE_LOINC_DIFF_SPECIMEN`) → incluida
     y reportada;
   - ausente de la lista activa → excluida como inactiva.
3. Crea/actualiza filas `procedure_type` por proveedor:
   ```
   grp  OEP{providerId}-{panelId}   ej. OEP2-5      (parent = 0, nivel top)
     ord OE{providerId}-T{testId}   ej. OE2-T42     (cuelga de su panel vía parent)
   ```
   `parent` referencia el **`procedure_type_id`** (PK autoincremental) del grp
   del panel — no su código. Los códigos son determinísticos por
   `(proveedor, prueba)` / `(proveedor, panel)`, así que re-importar es
   idempotente y nunca choca entre laboratorios. Los nombres se truncan a los 63
   caracteres de la columna sin cortar palabras; la unicidad depende **solo** de
   `procedure_code`.
4. Genera una fila `mod_openelis_code_mapping` por prueba importada con
   `import_source = 'catalog_import'` y el LOINC cuando existe.

La página ofrece una **vista previa** (dry-run, sin escrituras) y una acción
**confirmar** separada, ambas por AJAX. La importación de **un proveedor**
corre dentro de una única transacción. `admin_mapping.php` sigue siendo la vía
de ajuste fino manual y convive (sus filas quedan con `provider_id = 0`,
`import_source = 'manual'`).

### 🔧 Interfaz de administración

Acceder vía **Laboratorio → Mapeo códigos OpenELIS** (requiere ACL `admin/super`).

Características:
- 📋 Lista todos los procedimientos activos de OpenEMR (`procedure_type = 'ord'`)
- 🔍 Búsqueda por nombre, código o estándar (CPT4, SNOMED, LOINC)
- ➕ Asignar nuevos mapeos con formularios inline
- ✏️ Editar mapeos existentes
- 🔄 Alternar estado activo/inactivo
- 📄 Resultados paginados (20 por página)

### 💻 Uso de la API

#### Resolver un mapeo individual

```php
use OpenEMR\Modules\OpenElis\CodeMappingService;

// Devuelve openelis_test_id o null
$elisTestId = CodeMappingService::resolveOpenElisTestId('GLUC-001');

// Devuelve openelis_test_id o el código original como fallback
$elisTestId = CodeMappingService::resolveWithFallback('GLUC-001');
// Si tiene mapeo: devuelve "42"
// Si no tiene mapeo: devuelve "GLUC-001"
```

#### Resolución en lote

```php
$procedureCodes = ['GLUC-001', 'HEMO-002', 'BIO-003'];

foreach ($procedureCodes as $code) {
    $elisId = CodeMappingService::resolveOpenElisTestId($code);
    if ($elisId !== null) {
        // ✅ Tiene mapeo — enviar a OpenELIS
        sendOrderToElis($elisId);
    } else {
        // ⚠️ Sin mapeo — omitir o registrar
        error_log("Sin mapeo para procedimiento: " . $code);
    }
}
```

---

## ⚙️ Configuración

| Parámetro | Descripción | Valor por defecto |
|-----------|-------------|-------------------|
| Directorio del módulo | `openelis` | — |
| ACL de admin | `admin/super` | Solo superusuario |
| Ítems por página | 20 | Configurable en código |
| Filtro de procedimientos | `procedure_type = 'ord'` | Solo procedimientos ordenables |

---

## 🛠 Desarrollo

### Stack tecnológico

- 🐘 **Backend:** PHP 8.1+ con funciones SQL legacy de OpenEMR (`sqlQuery`, `sqlStatement`, `sqlFetchArray`)
- 🗄️ **Base de datos:** MySQL/MariaDB (InnoDB, utf8mb4)
- 🎨 **Frontend:** Bootstrap 5 (vía `Header::setupHeader` de OpenEMR)
- 🔐 **Seguridad:** Tokens CSRF (`CsrfUtils`), escape XSS (`attr()`, `text()`, `xlt()`), ACL (`AclMain::aclCheckCore`)
- 📦 **Autocarga:** PSR-4 vía `ModulesClassLoader`

### Convenciones de OpenEMR

Este módulo sigue los estándares de módulos custom de OpenEMR:

- ✅ `table.sql` con directivas `#IfNotTable` para migraciones idempotentes
- ✅ `openemr.bootstrap.php` para registro de namespace
- ✅ `ModuleManagerListener` extiende `AbstractModuleActionListener`
- ✅ Include de `globals.php`: auto-detectado por los scripts web (suben desde su
  propia carpeta hasta encontrar `globals.php`, así funcionan desde la carpeta
  del módulo o desde la raíz `public/`) — ver [Despliegue](#-despliegue)
- ✅ Todo el texto translatable vía funciones `xlt()` / `xl()`
- ✅ Sin PDO/mysqli directo — usa capa `sqlQuery()` / `sqlStatement()`

### Referencia de OpenELIS Global 2

El catálogo de pruebas se lee por la API REST sobre HTTPS (el laboratorio entrega
solo un usuario/clave de API, no credenciales de base de datos):

| Endpoint | Método | Notas |
|----------|--------|-------|
| `/OpenELIS-Global/rest/TestNamesProvider?testId={id}` | GET | Devuelve el nombre de UNA prueba (`name.spanish` / `name.english`) para un solo id numérico. `testId=all` → HTTP 500, por lo que los ids se sondean de a uno en un rango configurable. NO devuelve LOINC. |

- El sincronizador (`src/Catalog/OpenElisCatalog.php`) se dispara desde
  **Configuración OpenELIS** (`public/openelis_config.php`). Recorre el rango de
  ids configurado y guarda los resultados en la tabla espejo local
  `mod_openelis_test_catalog`.
- La página de mapeo (`public/admin_mapping.php`) lee ese espejo local para
  autosugerir el id/nombre de prueba de OpenELIS al asignar un mapeo — sin llamadas
  a la API por cada tecla.
- El LOINC no lo entrega este endpoint, por lo que es opcional / se ingresa a mano.
- El diseño previo (leer las tablas `clinlims.*` de PostgreSQL de OpenELIS
  directamente) se descartó porque el laboratorio no comparte credenciales de BD.

### Importar el catálogo al árbol de procedimientos

El endpoint REST `TestNamesProvider` devuelve solo el **nombre** de una prueba por
id — no expone la **estructura de paneles** ni la **agrupación por sección**. Para
armar el catálogo de procedimientos de OpenEMR fielmente, el laboratorio exporta
el catálogo en dos archivos CSV que el admin deja junto a los scripts desplegados
del módulo:

| Archivo | Columnas | Propósito |
|---------|----------|-----------|
| `catalog.csv` | `test_id, test_name, loinc, section_name, is_active` | Las pruebas, agrupadas por sección |
| `panels.csv`  | `panel_id, panel_name, test_id, test_name` | Composición de paneles (qué prueba pertenece a qué panel) |

> 🔧 **De dónde salen los CSV.** Se exportan de la base de datos PostgreSQL propia
> de OpenELIS (el contenedor sidecar `openelisglobal-database`) — el único lugar
> que conoce secciones, paneles y LOINC. La API REST (p. ej. un usuario tipo
> "Analyzer import") solo devuelve nombres, no esta estructura. Para **tu propia
> instancia** se exportan desde el contenedor de la base de datos. Para una
> **instancia remota/alquilada** donde solo tenés usuario de API (sin acceso a
> base de datos), pedí al dueño de la instancia estos dos archivos en exactamente
> este formato.

Desde **Configuración OpenELIS** → **Importar catálogo en procedimientos de
laboratorio**, se elige un proveedor de laboratorio (el `lab_id` que se estampa en
las filas importadas) y se importa. El importador
(`src/Service/ProcedureCatalogImporter.php`) arma el árbol `procedure_type`:

```
grp  OES-<sección>                       ej. OES-hematology ("Hematology")
  grp  OEP-<id_panel>                    ej. OEP-2 ("NFS") anidado en su sección
    ord  OE-<id_test>                    ej. OE-13 (cuelga de su panel)
  ord  OE-<id_test>                      los tests fuera de panel cuelgan de la sección
```

Como `procedure_type` solo admite **un padre por fila**, cada prueba cuelga de su
panel o (si no pertenece a ningún panel) directamente de su grupo de sección. El
LOINC se guarda en `procedure_type.standard_code` como `LOINC:<código>`. Como
conveniencia, cada prueba también recibe una fila en `mod_openelis_code_mapping`
(`OE-<id_test>` → `<id_test>`), así los tests importados quedan listos para
enviar. Re-importar actualiza las filas existentes (no duplica).

> ⚠️ En producción los CSV deben colocarse en la **misma carpeta que el
> `openelis_config.php` desplegado** (es decir `<raiz>/public/modules/openelis/`),
> porque el importador los lee relativo al directorio del script. En dev eso es
> `oe-module-openelis-interface/public/`.

### Referencia de capacidades FHIR de OpenELIS

OpenELIS Global 2 (Development-Class) expone un subconjunto de recursos HAPI FHIR
R4: `DiagnosticReport, Observation, Organization, Patient, Practitioner,
ServiceRequest, Specimen`. NO expone un recurso de catálogo de pruebas
(`ObservationDefinition` es desconocido; `Observation`/`ServiceRequest` son solo
para resultados/órdenes), por eso el catálogo se obtiene vía el proveedor REST de
arriba.

---

## 📜 Licencia

Este proyecto está bajo la **Licencia Pública General de GNU v3.0** — ver el archivo [LICENSE](https://github.com/openemr/openemr/blob/master/LICENSE) para más detalles.

---

## 🤝 Contribuir

1. Hacer fork del repositorio
2. Crear una rama de feature (`git checkout -b feature/caracteristica-increible`)
3. Confirmar los cambios (`git commit -m 'Agregar característica increíble'`)
4. Push a la rama (`git push origin feature/caracteristica-increible`)
5. Abrir un Pull Request

---

## 📧 Contacto

Para problemas o preguntas, abrir un issue en [GitHub](https://github.com/anomalyco/opencode/issues).
