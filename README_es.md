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
│   ├── 📂 Client/                        # Cliente REST (próximamente)
│   ├── 📂 Mappers/                       # Mapeadores de datos (próximamente)
│   └── 📂 Service/                       # Servicios de negocio (próximamente)
│
├── 📂 public/
│   ├── 🖥️ admin_mapping.php              # Interfaz admin para CRUD de mapeo de códigos
│   └── 🖥️ order_status_view.php          # Vista de estado de órdenes (próximamente)
│
├── 📂 config/
│   └── ⚙️ openelis_config.php            # Configuración del módulo
│
├── 📂 api/
│   └── 🔗 webhook_results.php            # Endpoint de webhook de resultados (próximamente)
│
├── 📄 README.md                          # Documentación (inglés)
└── 📄 README_es.md                       # Este archivo (español)
```

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

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | INT (PK) | Identificador autoincremental |
| `openemr_procedure_code` | VARCHAR(50) UNIQUE | Código de procedimiento de OpenEMR |
| `openemr_procedure_name` | VARCHAR(255) | Nombre para mostrar del procedimiento |
| `openelis_test_id` | VARCHAR(50) | ID de prueba en OpenELIS |
| `openelis_test_name` | VARCHAR(255) | Nombre de la prueba en OpenELIS |
| `is_active` | TINYINT(1) | 1 = activo, 0 = inactivo |

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
- ✅ Include de `globals.php`: `dirname(__FILE__, 6)`
- ✅ Todo el texto translatable vía funciones `xlt()` / `xl()`
- ✅ Sin PDO/mysqli directo — usa capa `sqlQuery()` / `sqlStatement()`

### Referencia de OpenELIS Global 2

| Entidad | Tabla | Campo ID | Resolución de nombre |
|---------|-------|----------|---------------------|
| Prueba | `clinlims.test` | `id` (string numérico) | `name_localization_id` → fallback a `description` |
| Sección de prueba | `clinlims.test_section` | `id` (string numérico) | `name_localization_id` → fallback a `description` |

> ⚠️ **Nota:** Los nombres de pruebas en OpenELIS están localizados vía la tabla `localization`. La columna `description` sirve como fallback cuando no hay nombre localizado.

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
