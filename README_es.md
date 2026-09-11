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
│       └── 🗄️ CatalogImportService.php   # Catálogo REST → procedure_type + mapeos (por proveedor)
│
├── 📂 public/                            # ⭐ Scripts web — se copian a <raiz_openemr>/public/modules/openelis/
│   ├── 🖥️ admin_mapping.php              # Interfaz admin para CRUD de mapeo de códigos
│   ├── 🖥️ pending_orders.php             # Órdenes pendientes + UI para enviar a OpenELIS
│   ├── 🖥️ send_order_action.php          # Endpoint AJAX (POST → JSON)
│   ├── 🖥️ catalog_import.php             # Importación masiva de catálogo (vista previa + confirmar)
│
├── 📂 sql/
│   └── 📄 lang_custom.sql                # Traducciones custom
│
├── 📂 patches/
│   └── 📄 common.php.patch.txt           # Parche (8.0.0): botón "Enviar a OpenELIS"
│   └── 📄 procedure_provider_edit.php.patch.txt  # Parche (8.0.0): credenciales de catálogo en form de proveedores
│   └── 📄 find_order_popup.php.patch.txt # Parche (8.2.0): filtro estricto por proveedor y tipo de pedido en el picker de procedimientos
│   └── 📂 openemr/                       # Archivos COMPLETOS parcheados (listos para producción)
│       └── 📂 interface/forms/procedure_order/common.php
│       └── 📂 interface/orders/procedure_provider_edit.php
│       └── 📂 interface/orders/find_order_popup.php
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

4. Tras actualizar la carpeta `public/` del módulo (scripts), volver a copiar su
   contenido a la raíz `public/modules/openelis/` para que la versión desplegada
   quede sincronizada.

### Mapa de URLs (producción)

| Script | URL en producción |
|--------|-------------------|
| `send_order_action.php` | `https://hcd.origen.ar/public/modules/openelis/send_order_action.php` |
| `pending_orders.php`    | `https://hcd.origen.ar/public/modules/openelis/pending_orders.php` |
| `admin_mapping.php`     | `https://hcd.origen.ar/public/modules/openelis/admin_mapping.php` |
| `catalog_import.php`    | `https://hcd.origen.ar/public/modules/openelis/catalog_import.php` |

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
   - El submenú "OpenELIS" (con Importar Catálogo, Órdenes Pendientes, Mapeo de Códigos) aparecerá bajo la sección de Laboratorio

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
— nunca el usuario operativo Analyser Import; se editan en el formulario nativo
de proveedores, ver `patches/procedure_provider_edit.php`) y:

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
5. Extrae el **tipo de muestra** (`sampleType`) que trae el payload REST por
   prueba y lo resuelve contra la tabla de traducción única
   `mod_openelis_specimen_map` (ver abajo). El código SNOMED resuelto se guarda
   en `snomed_specimen` del mapeo automáticamente. Los tipos de muestra sin
   código se registran en la tabla (con `snomed_code = NULL`) y se reportan en
   el resumen del import bajo **"Muestras sin SNOMED"** para completarlos una
   sola vez.
6. **Sufijo de proveedor en el nombre visible**: cada fila `grp`/`ord` importada
   muestra `{Nombre} · {Laboratorio}` (ej. `Hemograma · Laboratorio Central`).
   Como cada proveedor apunta a un **OpenELIS distinto**, el sufijo hace
   distinguibles en el selector nativo de órdenes de OpenEMR el mismo análisis
   pedido a distintos laboratorios. El `openemr_procedure_name` del mapeo y el
   espejo `mod_openelis_test_catalog` guardan el **nombre limpio** (los
   reportes/resultados nunca muestran el sufijo).
7. **Reconciliación (desactivar sin borrar)**: al sincronizar, las filas
   propias del proveedor (`OE{p}-T*` y `OEP{p}-*`) que el catálogo ya **no**
   referencia pasan a `activity = 0` (nunca se borran) y su mapeo auto baja a
   `is_active = 0`. Si una prueba/panel vuelve a aparecer, se reactiva
   automáticamente (`activity = 1`, mapeo activo). Ambas transiciones
   (desactivación y reactivación) se **reportan en el resumen**, nada silencioso.
   Un test que **cambia de panel** se vuelve a colgar automáticamente bajo el
   panel nuevo (el `parent` se reasigna en cada sync; jamás queda huérfano sin
   existir). Salvaguardas: la reconciliación solo corre si la lectura vio paneles
   **y** pruebas; si algún panel devolvió miembros vacíos (posible fallo
   transitorio) se omite la pasada de desactivación de pruebas. Un error de
   catálogo transitorio **no** desactiva rows preexistentes.

### 🧬 `mod_openelis_specimen_map` — tipo de muestra → SNOMED (una sola vez)

OpenELIS expone el tipo de muestra de cada prueba **solo por nombre** (p. ej.
`Whole Blood`, `Serum`) — jamás entrega el código SNOMED. Para evitar escribir
SNOMED prueba por prueba, esta tabla pequeña traduce el *nombre* al concepto
SNOMED-CT y el importador la aplica automáticamente a cada test:

| Columna | Descripción |
|---------|-------------|
| `sample_type` | Nombre del tipo de muestra tal como lo devuelve el catálogo |
| `snomed_code` | Código SNOMED-CT del espécimen (ej. `119297000` = sangre). `NULL` = sin mapear aún |

Se siembra con los conceptos estándar más comunes (`Whole Blood` →
`119297000`, `Serum` → `119364003`, `Plasma` → `119361006`, `Urine`/`Urines` →
`122575006`); ajuste/agregue filas según los tipos reales de su catálogo. La
comparación es **exacta** (sin normalizar), así que una variante de ortografía
("Urines" con s, vista en payloads reales) es una fila distinta; cualquier tipo
de muestra no sembrado se auto-registra con `snomed_code = NULL` y se reporta en
el resumen del import como `specimen_unmapped`. El
SNOMED instalado en OpenEMR (`codes` + `code_types`, `ct_key` `SNOMED-CT`/`SNOMED`)
sirve para **validar y describir** cualquier código que agregue (no traduce
nombres automáticamente: la relación nombre→concepto es semántica y se cura una
vez aquí).

La página ofrece, **por cada proveedor**, un botón **"Vista previa"** (dry-run, sin
escrituras) y un botón **"Actualizar tests"** que sincroniza el catálogo de ese
OpenELIS, además del selector/confirmación clásico. Un proveedor sin credenciales
de catálogo (p. ej. el LAB01 manual) se muestra con la etiqueta **"Manual / sin
OpenELIS"** y sin botones. La importación de **un proveedor** corre dentro de una
única transacción. `admin_mapping.php` sigue siendo la vía de ajuste fino manual y
convive (sus filas quedan con `provider_id = 0`, `import_source = 'manual'`).

### 🔧 Interfaz de administración

Acceder vía **Laboratorio → OpenELIS → Mapeo de Códigos** (requiere ACL `admin/super`).

Características:
- 📋 Lista todos los procedimientos activos de OpenEMR (`procedure_type = 'ord'`),
  excluyendo estudios de **imagen** (por `order_type_name`/`procedure_type_name`)
- 🔍 Búsqueda por nombre, código o estándar (CPT4, SNOMED, LOINC)
- ➕ Asignar nuevos mapeos con un **selector con búsqueda** (picker) sobre el
  espejo local `mod_openelis_test_catalog`: buscar por nombre o ID y, con un
  clic, se rellenan el ID y el nombre del test de OpenELIS
- ⚡ Auto-relleno: el campo **LOINC** se pre-sugiere desde
  `procedure_type.standard_code` (regex `LOINC:xxxx`), y al elegir un test con
  tipo de muestra conocido el campo **SNOMED muestreo** se completa solo desde
  `mod_openelis_specimen_map` (si está vacío)
- ✏️ Editar mapeos existentes
- 🔄 Alternar estado activo/inactivo
- 📄 Resultados paginados (20 por página)

### 💻 Uso de la API

#### Resolver un mapeo individual

```php
use OpenEMR\Modules\OpenElis\CodeMappingService;

// Los mapeos están acotados por laboratorio: pase siempre el proveedor
// destino (procedure_providers.ppid). Una fila con provider_id = 0
// (legacy/sin asignar) actúa como fallback si el proveedor no tiene mapeo propio.
$labId = 4; // procedure_providers.ppid del laboratorio OpenELIS

// Devuelve openelis_test_id o null
$elisTestId = CodeMappingService::resolveOpenElisTestId('GLUC-001', $labId);

// Devuelve openelis_test_id o el código original como fallback
$elisTestId = CodeMappingService::resolveWithFallback('GLUC-001', $labId);
// Si tiene mapeo: devuelve "42"
// Si no tiene mapeo: devuelve "GLUC-001"
```

#### Resolución en lote

```php
$labId = 4; // procedure_providers.ppid del laboratorio destino
$procedureCodes = ['GLUC-001', 'HEMO-002', 'BIO-003'];

foreach ($procedureCodes as $code) {
    $elisId = CodeMappingService::resolveOpenElisTestId($code, $labId);
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

## 📤 Envío de órdenes (OpenEMR → OpenELIS)

1. `send_order_action.php?action=send` llama a `OrderSyncService::sendOrderToOpenElis()`:
   - sincroniza el paciente y el médico que ordena (Patient / Practitioner);
   - por cada línea de prueba crea un **Specimen** y un **ServiceRequest** (con
     `code.system = http://openelis-global.org/testId`, resuelto a través de
     `mod_openelis_code_mapping`) en el store FHIR co-residente de OpenELIS;
   - **publica un `Task`** (`status=requested`, `intent=order`,
     `basedOn` → los ServiceRequest creados, `for` → el Patient,
     `owner` → el Practitioner que ordena). Este es el recurso que OpenELIS
     sondea para mostrar la orden en la cola de **Electronic Orders**: sin él,
     el ServiceRequest queda dormido y nunca se importa como eOrder.
   - guarda las referencias en la orden: `mod_openelis_order_id` (primer ServiceRequest),
     `mod_openelis_task_id` (Task), `mod_openelis_patient_ref` (Patient).
2. La orden queda marcada `mod_openelis_sync_status = 'sent'`. Si los ServiceRequest
   se crearon pero el Task falló, el status queda `'error'` y la respuesta
   informa que el Task es obligatorio.

### Configuración del lado de OpenELIS (necesaria para EMR-LIS import)

La importación depende de propiedades de OpenELIS (no de la UI — Spring ignora
los cambios de la UI hasta reiniciar el webapp). En `common.properties` (montado
como secret Docker, p. ej. `/run/secrets/common.properties`) definí al menos:

```properties
# Desde dónde sondea órdenes entrantes. El módulo publica todo (ServiceRequest + Task)
# en el mismo store FHIR co-residente de OpenELIS, así que es la misma base:
org.openelisglobal.remote.source.uri=http://127.0.0.1:8081/fhir/
org.openelisglobal.remote.source.updateStatus=true
# Filtro de owner para el sondeo de Tasks: debe coincidir con la ref Practitioner que
# el módulo crea/sincroniza para el proveedor que ordena (Practitioner/<uuid>).
# Para encontrarlo: GET <uri>/Practitioner?identifier=<npi>
org.openelisglobal.remote.source.identifier=Practitioner/<uuid-del-que-ordena>
org.openelisglobal.task.useBasedOn=true
# Recursos suscriptos (el Task es el esencial):
org.openelisglobal.fhir.subscriber.resources=Task,Patient,ServiceRequest,DiagnosticReport,Observation,Specimen,Practitioner,Encounter
```

Después de guardar, reiniciar el webapp de OpenELIS y habilitar
**External orders** en Administración → External Orders.

---

## 📥 Recepción de resultados (laboratorio OpenELIS → OpenEMR)

Los resultados se traen **bajo demanda** (botones) — aún no hay sondeo automático.

### Flujo (por orden)

1. Al enviar una orden (`send_order_action.php?action=send`), cada línea de prueba
   guarda la referencia FHIR `ServiceRequest/<uuid>` que OpenELIS asignó
   (`procedure_order_code.mod_openelis_service_request_id`) y la orden guarda la
   referencia de paciente creada (`procedure_order.mod_openelis_patient_ref`).
2. `pending_orders.php` muestra un botón **Resultados** por cada orden enviada y
   un botón global **Buscar Resultados (Todas)**. Ambos hacen POST a
   `send_order_action.php` con `action=check_results` (una orden) o
   `action=check_results_all` (todas las órdenes con resultados pendientes, un
   cliente HTTP por proveedor).
3. `ResultSyncService::syncOrderResults($orderId)`:
   - verifica que el proveedor sea `WS` y tenga credenciales de catálogo;
   - verifica la identidad del paciente — `GET Patient/<ref>` y comprueba que el
     `nationalId` de OpenELIS sea igual a `patient_data.pubpid` de OpenEMR; ante
     una discrepancia la orden se rechaza (**no se importa nada**);
   - por cada línea de prueba pendiente consulta
     `DiagnosticReport?based-on=ServiceRequest/<uuid>`;
   - `ResultMapper::toOpenEmr()` mapea cada informe y sus observaciones a las
     tablas nativas de laboratorio de OpenEMR `procedure_report` /
     `procedure_result` (así los resultados aparecen en la UI estándar);
   - marca la línea con `mod_openelis_results_status = 'downloaded'`
     (re-ejecuciones idempotentes; las líneas ya descargadas se omiten).

### Valores de la columna de estado

`procedure_order_code.mod_openelis_results_status`: `pending` (al enviar),
`downloaded`, `error`.

### Observaciones / casos límite

- El subject del informe debe coincidir con el `Patient/<ref>` verificado de la
  orden; lo que no coincida se omite con una entrada `error_log`.
- `DiagnosticReport.status` final/amended/corrected → `report_status = 'complete'`;
  otros estados → `received`.
- Valores de `Observation`: `valueQuantity` (numérico `N`), `valueString`
  (texto `S`, largo → `L`), `valueCodeableConcept`, `valueInteger`. Rangos desde
  `referenceRange[].low/high/text`; banderas de anormalidad desde los codings de
  `interpretation` (H/HH→high, L/LL→low, A/AA→yes).
- Use `public/probe_results_api.php` en un servidor de prueba para inspeccionar
  los payloads reales (`?ppid=<id>` lista los informes recientes;
  `?ppid=<id>&sr=ServiceRequest/<uuid>` muestra los informes de una prueba + sus
  observaciones) antes de confiar en el mapeo.

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

- La tabla espejo local `mod_openelis_test_catalog` se mantiene fresca con la
  importación de catálogo (`public/catalog_import.php` / `CatalogImportService`),
  que hace upsert de cada prueba importada por su id de OpenELIS (incluye el
  `sample_type` cuando la API lo entrega).
- La página de mapeo (`public/admin_mapping.php`) lee ese espejo local para
  autosugerir el id/nombre de prueba de OpenELIS al asignar un mapeo — sin llamadas
  a la API por cada tecla.
- El LOINC no lo entrega este endpoint, por lo que es opcional / se ingresa a mano
  (o se pre-sugiere desde `procedure_type.standard_code` en el formulario de mapeo).
- El diseño previo (leer las tablas `clinlims.*` de PostgreSQL de OpenELIS
  directamente) se descartó porque el laboratorio no comparte credenciales de BD.

### Importar el catálogo (antes import CSV)

Las versiones anteriores importaban el catálogo desde dos archivos CSV de
exportación que el admin dejaba junto a los scripts desplegados
(`catalog.csv` / `panels.csv`, vía
`src/Service/ProcedureCatalogImporter.php`) desde la página **Configuración
OpenELIS**. Esa página, la clase importadora de CSV y los archivos de ejemplo
fueron **eliminados**. La importación vía REST en
[`catalog_import.php`](#-importación-de-catálogo-rest--catalog_importphp)
cubre el mismo objetivo (paneles + pruebas ordenables → árbol `procedure_type` +
`mod_openelis_code_mapping`) usando las credenciales de catálogo ADMIN de cada
proveedor, sin requerir acceso a la base de datos ni exportaciones CSV.

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
