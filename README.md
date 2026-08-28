# 🏥 OpenEMR ↔ OpenELIS Interface

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![OpenEMR](https://img.shields.io/badge/OpenEMR-8.0%2F8.2-green.svg)](https://www.open-emr.org)
[![OpenELIS](https://img.shields.io/badge/OpenELIS-Global%202-orange.svg)](https://github.com/DIGI-UW/OpenELIS-Global-2)

**Custom module for OpenEMR** that integrates with [OpenELIS Global 2](https://github.com/DIGI-UW/OpenELIS-Global-2) to synchronize lab orders, patients, and results.

> 📍 **Module path:** `interface/modules/custom_modules/openelis/`
>
> 🌐 **Instances:** [hcd.origen.ar](http://hcd.origen.ar) (OpenEMR) ↔ [elis.origen.ar](http://elis.origen.ar) (OpenELIS)

---

## 📋 Table of Contents

- [ Features](#-features)
- [ Architecture](#-architecture)
- [ Directory Structure](#-directory-structure)
- [ Deployment](#-deployment)
- [ Installation](#-installation)
- [ Code Mapping](#-code-mapping)
- [ API Usage](#-api-usage)
- [ Configuration](#-configuration)
- [ Development](#-development)
- [ License](#-license)

---

## ✨ Features

| Feature | Status | Description |
|---------|--------|-------------|
| 🔀 Code Mapping | ✅ Done | Map OpenEMR procedure codes to OpenELIS test IDs |
| 🧪 Lab Order Sync | 🔜 Planned | Send lab orders from OpenEMR to OpenELIS |
| 👤 Patient Sync | 🔜 Planned | Synchronize patient demographics |
| 📊 Result Retrieval | 🔜 Planned | Fetch lab results via webhook |
| 🔔 Result Notifications | 🔜 Planned | Notify patients and providers of results |

---

## 🏗 Architecture

```
┌─────────────────────────────────┐         ┌─────────────────────────────────┐
│          OpenEMR                │         │          OpenELIS               │
│      (hcd.origen.ar)            │  REST   │      (elis.origen.ar)           │
│                                 │◄───────►│                                 │
│  ┌───────────────────────────┐  │  HL7    │  ┌───────────────────────────┐  │
│  │   oe-module-openelis      │  │         │  │     OpenELIS Global 2     │  │
│  │                           │  │         │  │                           │  │
│  │  • Code Mapping           │  │         │  │  • Test Catalog           │  │
│  │  • Order Sync (planned)   │  │         │  │  • Patient Management     │  │
│  │  • Patient Sync (planned) │  │         │  │  • Order Management       │  │
│  │  • Results Webhook        │  │         │  │  • Result Reporting       │  │
│  └───────────────────────────┘  │         │  └───────────────────────────┘  │
└─────────────────────────────────┘         └─────────────────────────────────┘
```

---

## 📁 Directory Structure

```
openelis/
├── 📄 table.sql                          # SQL migration (#IfNotTable directive)
├── 📄 version.php                        # Module version (1.0.0)
├── 📄 info.txt                           # Module description
├── 📄 openemr.bootstrap.php              # Bootstrap: namespace + event subscription
├── 📄 ModuleManagerListener.php          # Install/enable/disable lifecycle
│
├── 📂 src/
│   ├── 🔧 Bootstrap.php                  # Menu registration + event listeners
│   ├── 🔧 CodeMappingService.php         # Reusable code mapping queries
│   ├── 🔧 OrderSyncService.php           # syncPatient/syncPractitioner/sendOrder services
│   ├── 📂 Client/                        # OpenElisApiClient (cURL FHIR client)
│   ├── 📂 Mappers/                       # Patient/Practitioner/Order FHIR mappers
│   └── 📂 Service/                       # Business services
│
├── 📂 public/                            # ⭐ Web scripts — copied to <openemr_root>/public/modules/openelis/
│   ├── 🖥️ admin_mapping.php              # Admin UI for code mapping CRUD
│   ├── 🖥️ pending_orders.php             # Pending orders + Send-to-OpenELIS UI
│   └── 🖥️ send_order_action.php          # AJAX endpoint (POST → JSON)
│
├── 📂 sql/
│   └── 📄 lang_custom.sql                # Custom translations
│
├── 📂 patches/
│   └── 📄 common.php.patch.txt           # Optional patch for order form Send button
│
├── 📄 README.md                          # This file (English)
└── 📄 README_es.md                       # Documentation (Spanish)
```

> ⭐ **IMPORTANT:** The files under `public/` are the only ones that must be
> directly reachable by the web server. OpenEMR's own `interface/` tree is
> protected and does not serve arbitrary module scripts, so in production the
> contents of `public/` are **copied** to the OpenEMR root
> `public/modules/openelis/` folder.
> See [Deployment](#-deployment).

---

## 🚀 Deployment

OpenEMR's `interface/` tree is protected by the web server and **does not serve
arbitrary module scripts reachable by URL**. To make the module's web pages and
AJAX endpoints reachable, the contents of `public/` must be **copied** into the
OpenEMR root `public/modules/openelis/` folder.

> 💡 **Why not point at the module folder?** nginx (and OpenEMR's security
> layer) will 404 or redirect any module script requested via
> `interface/modules/custom_modules/...`. The scripts `bootstrap` by walking up
> until they find `globals.php`, so they work correctly from the root
> `public/modules/openelis/` folder.

### Steps

1. Install the module (see [Installation](#-installation)).
2. **Copy the web scripts** to the OpenEMR root `public/modules/openelis/`
   folder:

   ```bash
   mkdir -p /var/www/html/origen.ar/hcd/public/modules/openelis
   cp interface/modules/custom_modules/openelis/public/*.php \
      /var/www/html/origen.ar/hcd/public/modules/openelis/
   ```

3. **Verify** the endpoints respond (no 404). Test the AJAX endpoint from the
   server console:

   ```bash
   curl -s -X POST "https://hcd.origen.ar/public/modules/openelis/send_order_action.php" \
        -d "order_id=27" -d "action=send"
   ```

4. After updating the module's `public/*.php`, re-copy them to the root
   `public/modules/openelis/` folder so the deployed version stays in sync.

### URL map (production)

| Script | Production URL |
|--------|----------------|
| `send_order_action.php` | `https://hcd.origen.ar/public/modules/openelis/send_order_action.php` |
| `pending_orders.php`    | `https://hcd.origen.ar/public/modules/openelis/pending_orders.php` |
| `admin_mapping.php`     | `https://hcd.origen.ar/public/modules/openelis/admin_mapping.php` |

> ⚠️ **Root detection:** The scripts locate OpenEMR's `globals.php` automatically
> by walking up from their own directory. They check both `<dir>/globals.php` and
> `<dir>/interface/globals.php` at each level, so they work whether `globals.php`
> sits at the OpenEMR root or (as in this deployment) under the root's
> `interface/` folder. This also works from the module folder (dev) or the root
> `public/modules/<name>/` folder (prod), so no code change is needed between
> environments.

---

## 🚀 Installation

### Prerequisites

- ✅ OpenEMR 8.0 or 8.2
- ✅ OpenELIS Global 2 instance running
- ✅ PHP 8.1+
- ✅ MySQL/MariaDB with InnoDB support

### Steps

1. **Copy the module** to your OpenEMR installation:

   ```bash
   cp -r oe-module-openelis-interface/ \
         /path/to/openemr/interface/modules/custom_modules/openelis/
   ```

2. **Register the module** in OpenEMR:
   - Navigate to **Admin → Modules** (Laminas Module Manager)
   - Click **Register** and select the `openelis` directory
   - The `table.sql` will execute automatically, creating the `mod_openelis_code_mapping` table

3. **Enable the module:**
   - In the Module Manager, click **Enable** on the OpenELIS Interface module
   - The menu item "Mapeo códigos OpenELIS" will appear under the Lab section

4. **Verify installation:**

   ```sql
   SHOW TABLES LIKE 'mod_openelis_code_mapping';
   -- Should return 1 row
   ```

---

## 🔀 Code Mapping

The code mapping layer translates OpenEMR procedure codes (`procedure_type.procedure_code`) to OpenELIS test IDs (`test.id`).

### 📊 Table: `mod_openelis_code_mapping`

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Auto-increment identifier |
| `openemr_procedure_code` | VARCHAR(50) UNIQUE | OpenEMR procedure code |
| `openemr_procedure_name` | VARCHAR(255) | Procedure display name |
| `openelis_test_id` | VARCHAR(50) | OpenELIS test ID |
| `openelis_test_name` | VARCHAR(255) | Test display name in OpenELIS |
| `is_active` | TINYINT(1) | 1 = active, 0 = inactive |

### 🔧 Admin Interface

Access via **Lab → Mapeo códigos OpenELIS** (requires `admin/super` ACL).

Features:
- 📋 List all active OpenEMR procedures (`procedure_type = 'ord'`)
- 🔍 Search by name, code, or standard (CPT4, SNOMED, LOINC)
- ➕ Assign new mappings with inline forms
- ✏️ Edit existing mappings
- 🔄 Toggle active/inactive status
- 📄 Paginated results (20 per page)

### 💻 API Usage

#### Resolve a single mapping

```php
use OpenEMR\Modules\OpenElis\CodeMappingService;

// Returns openelis_test_id or null
$elisTestId = CodeMappingService::resolveOpenElisTestId('GLUC-001');

// Returns openelis_test_id or falls back to the original code
$elisTestId = CodeMappingService::resolveWithFallback('GLUC-001');
// If mapped: returns "42"
// If not mapped: returns "GLUC-001"
```

#### Batch resolution

```php
$procedureCodes = ['GLUC-001', 'HEMO-002', 'BIO-003'];

foreach ($procedureCodes as $code) {
    $elisId = CodeMappingService::resolveOpenElisTestId($code);
    if ($elisId !== null) {
        // ✅ Mapped — send to OpenELIS
        sendOrderToElis($elisId);
    } else {
        // ⚠️ No mapping — skip or log
        error_log("No mapping for procedure: " . $code);
    }
}
```

---

## ⚙️ Configuration

| Setting | Description | Default |
|---------|-------------|---------|
| Module directory | `openelis` | — |
| Admin ACL | `admin/super` | Superuser only |
| Items per page | 20 | Configurable in code |
| Procedure filter | `procedure_type = 'ord'` | Orderable procedures only |

---

## 🛠 Development

### Tech Stack

- 🐘 **Backend:** PHP 8.1+ with OpenEMR legacy SQL functions (`sqlQuery`, `sqlStatement`, `sqlFetchArray`)
- 🗄️ **Database:** MySQL/MariaDB (InnoDB, utf8mb4)
- 🎨 **Frontend:** Bootstrap 5 (via OpenEMR's `Header::setupHeader`)
- 🔐 **Security:** CSRF tokens (`CsrfUtils`), XSS escaping (`attr()`, `text()`, `xlt()`), ACL (`AclMain::aclCheckCore`)
- 📦 **Autoloading:** PSR-4 via `ModulesClassLoader`

### OpenEMR Conventions

This module follows OpenEMR's custom module standards:

- ✅ `table.sql` with `#IfNotTable` directives for idempotent migrations
- ✅ `openemr.bootstrap.php` for namespace registration
- ✅ `ModuleManagerListener` extending `AbstractModuleActionListener`
- ✅ `globals.php` include path: auto-detected by the web scripts (they walk up
  their own directory until `globals.php` is found, so they work from the module
  folder or the root `public/` folder) — see [Deployment](#-deployment)
- ✅ All text translatable via `xlt()` / `xl()` functions
- ✅ No direct PDO/mysqli — uses `sqlQuery()` / `sqlStatement()` layer

### OpenELIS Global 2 Reference

| Entity | Table | ID Field | Name Resolution |
|--------|-------|----------|-----------------|
| Test | `clinlims.test` | `id` (numeric string) | `name_localization_id` → `description` fallback |
| Test Section | `clinlims.test_section` | `id` (numeric string) | `name_localization_id` → `description` fallback |

> ⚠️ **Note:** OpenELIS test names are localized via the `localization` table. The `description` column serves as a fallback when no localized name exists.

---

## 📜 License

This project is licensed under the **GNU General Public License v3.0** — see the [LICENSE](https://github.com/openemr/openemr/blob/master/LICENSE) file for details.

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📧 Contact

For issues or questions, open an issue on [GitHub](https://github.com/anomalyco/opencode/issues).
