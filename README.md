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
│   ├── 📂 Client/                        # HTTP clients (FHIR + catalog REST)
│   │   ├── 🔌 OpenElisApiClient.php      # FHIR R4 client (order send flow)
│   │   └── 🔌 CatalogApiClient.php       # test-catalog REST client (admin catalog user)
│   ├── 📂 Mappers/                       # Patient/Practitioner/Order FHIR mappers
│   └── 📂 Service/                       # Business services
│       ├── 🗄️ CatalogImportService.php   # Catalog REST → procedure_type + mappings (per provider)
│       └── 🗄️ ProcedureCatalogImporter.php # Legacy CSV → procedure_type (grp + ord)
│
├── 📂 public/                            # ⭐ Web scripts — copied to <openemr_root>/public/modules/openelis/
│   ├── 🖥️ admin_mapping.php              # Admin UI for code mapping CRUD
│   ├── 🖥️ pending_orders.php             # Pending orders + Send-to-OpenELIS UI
│   ├── 🖥️ send_order_action.php          # AJAX endpoint (POST → JSON)
│   ├── 🖥️ catalog_import.php             # Bulk catalog import (preview + confirm)
│   ├── 🗂️ catalog.csv                    # OpenELIS test catalog export (drop-in import, legacy)
│   └── 🗂️ panels.csv                     # OpenELIS panel export (drop-in import, legacy)
│
├── 📂 sql/
│   └── 📄 lang_custom.sql                # Custom translations
│
├── 📂 patches/
│   └── 📄 common.php.patch.txt           # Patch (8.0.0): "Send to OpenELIS" button
│   └── 📄 procedure_provider_edit.php.patch.txt  # Patch (8.0.0): catalog credentials in providers form
│   └── 📂 openemr/                       # COMPLETE patched files (production-ready)
│       └── 📂 interface/forms/procedure_order/common.php
│       └── 📂 interface/orders/procedure_provider_edit.php
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
   cp interface/modules/custom_modules/openelis/public/* \
      /var/www/html/origen.ar/hcd/public/modules/openelis/
   ```

3. **Verify** the endpoints respond (no 404). Test the AJAX endpoint from the
   server console:

   ```bash
   curl -s -X POST "https://hcd.origen.ar/public/modules/openelis/send_order_action.php" \
        -d "order_id=27" -d "action=send"
   ```

4. After updating the module's `public/` folder (scripts and/or CSV exports),
   re-copy its contents to the root `public/modules/openelis/` folder so the
   deployed version stays in sync.

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
   - The "OpenELIS" submenu (Import Catalog, Pending Orders, Code Mapping, Settings) will appear under the Lab section

4. **Verify installation:**

   ```sql
   SHOW TABLES LIKE 'mod_openelis_code_mapping';
   -- Should return 1 row
   ```

---

## 🔀 Code Mapping

The code mapping layer translates OpenEMR procedure codes (`procedure_type.procedure_code`) to OpenELIS test IDs (`test.id`).

### 📊 Table: `mod_openelis_code_mapping`

The table is **multi-lab**: the same OpenEMR procedure code can be ordered
against different labs, and each lab resolves it to a different OpenELIS test.
The unique key is therefore `(openemr_procedure_code, provider_id)`; `provider_id`
references `procedure_providers.ppid` (0 = legacy/unassigned rows).

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (PK) | Auto-increment identifier |
| `provider_id` | INT | `procedure_providers.ppid` this mapping belongs to (0 = unassigned) |
| `openemr_procedure_code` | VARCHAR(50) | OpenEMR procedure code (unique per provider) |
| `openemr_procedure_name` | VARCHAR(255) | Procedure display name |
| `openelis_test_id` | VARCHAR(50) | OpenELIS test ID |
| `openelis_test_name` | VARCHAR(255) | Test display name in OpenELIS |
| `openelis_panel_id` | VARCHAR(20) | OpenELIS panel the test was imported from (informational) |
| `openelis_panel_name` | VARCHAR(255) | OpenELIS panel name (informational) |
| `is_active` | TINYINT(1) | 1 = active, 0 = inactive |
| `import_source` | ENUM(`'manual'`,`'catalog_import'`) | `manual` rows are never overwritten by the importer |
| `imported_at` | DATETIME | Last time the catalog importer touched this row |
| `loinc_code` | VARCHAR(20) | LOINC code (adds FHIR coding; used by the "fully mapped" badge) |
| `snomed_specimen` / `snomed_finding` / `units` | VARCHAR | Optional FHIR standardization columns |

The **import behavior** for collisions:
- `import_source = 'catalog_import'` rows (generated by the importer) are
  always overwritten in place on re-import (idempotent).
- `import_source = 'manual'` rows (created by hand in `admin_mapping.php`) are
  **never overwritten**: a re-import only refreshes
  `openelis_panel_id` / `openelis_panel_name` / `imported_at` and reports the
  mapping as a **conflict** for human review.

### 🧩 Catalog import (REST) — `catalog_import.php`

The recommended way to build the procedure catalog for a provider. Reads the
OpenELIS REST API `GET /OpenELIS-Global/rest/test-catalog/*` (requires an
**OpenELIS ADMIN** user, configured per provider as
`procedure_providers.mod_openelis_catalog_login` / `mod_openelis_catalog_password`
— never the operational Analyser Import user; edited on the native Procedure
Providers form, see `patches/procedure_provider_edit.php`) and:

1. Lists active panels and, per panel, its ordered tests.
2. Cross-checks every test against the active-tests list
   (`errorCount` / `findings`):
   - `errorCount > 0` or any ERROR finding → **excluded** (e.g. an orphan test
     missing its sample-type link, `SAMPLE_TYPE_LINKS`);
   - warning-only findings (e.g. `DUPLICATE_LOINC_DIFF_SPECIMEN`) → included
     and reported;
   - not present in the active list → excluded as inactive.
3. Creates/updates `procedure_type` rows per provider:
   ```
   grp  OEP{providerId}-{panelId}   e.g. OEP2-5      (parent = 0, top-level)
     ord OE{providerId}-T{testId}   e.g. OE2-T42     (hangs from its panel via parent)
   ```
   `parent` references the panel group's **`procedure_type_id`** (AUTO_INCREMENT
   primary key), not its code. Codes are deterministic per
   `(provider, test)`/`(provider, panel)`, so re-importing is idempotent and
   never collides between labs. Names are truncated to the column's 63 chars
   without splitting words; uniqueness depends **only** on `procedure_code`.
4. Generates one `mod_openelis_code_mapping` row per imported test with
   `import_source = 'catalog_import'` and the LOINC code when available.

The page supports a **dry-run preview** (no writes) and a separate **confirm**
action, both via AJAX. The whole import for **one provider** runs inside a single
transaction. `admin_mapping.php` remains the manual fine-tuning path and coexists
(its rows default to `provider_id = 0`, `import_source = 'manual'`).

### 🔧 Admin Interface

Access via **Lab → OpenELIS → Code Mapping** (requires `admin/super` ACL).

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

The test catalog is read over the HTTPS REST API (the lab provides only an API
user/password, not database credentials):

| Endpoint | Method | Notes |
|----------|--------|-------|
| `/OpenELIS-Global/rest/TestNamesProvider?testId={id}` | GET | Returns one test's name (`name.spanish` / `name.english`) for a single numeric id. `testId=all` → HTTP 500, so ids are probed one by one across a configurable range. Does NOT return LOINC. |

- The synchronizer (`src/Catalog/OpenElisCatalog.php`) is triggered from
  **OpenELIS Settings** (`public/openelis_config.php`). It iterates the
  configured id range and stores results in the local mirror table
  `mod_openelis_test_catalog`.
- The mapping page (`public/admin_mapping.php`) reads that local mirror to
  autosuggest the OpenELIS test id/name when assigning a mapping — no per-keystroke
  API calls.
- LOINC is not provided by this endpoint, so it is optional / entered manually.
- The prior design (reading OpenELIS's `clinlims.*` PostgreSQL tables directly)
  was abandoned because the lab does not share database credentials.

### Importing the catalog into the procedure tree

The REST `TestNamesProvider` endpoint returns only a test's **name** by id — it
does not expose the **panel structure** or the **section grouping**. To build the
OpenEMR procedure catalog faithfully, the lab exports the catalog as two CSV
files that the admin drops next to the module's deployed scripts:

| File | Columns | Purpose |
|------|---------|---------|
| `catalog.csv` | `test_id, test_name, loinc, section_name, is_active` | The tests, grouped by section |
| `panels.csv`  | `panel_id, panel_name, test_id, test_name` | Panel composition (which test belongs to which panel) |

> 🔧 **Where the CSVs come from.** They are exported from OpenELIS's own
> PostgreSQL database (the `openelisglobal-database` sidecar container) — the only
> place that knows sections, panels and LOINC. The REST API (a "Analyzer import"
> user, for instance) exposes names only, not this structure. For **your own
> instance** you export them from the database container. For a **remote/rented
> instance** where you only have an API user (no database access), request these
> two files from the instance owner in exactly this format.

From **OpenELIS Settings** → **Import catalog into lab procedures**, pick a lab
provider (the `lab_id` stamped on imported rows) and import. The importer
(`src/Service/ProcedureCatalogImporter.php`) builds the `procedure_type` tree:

```
grp  OES-<section>                        e.g. OES-hematology ("Hematology")
  grp  OEP-<panel_id>                     e.g. OEP-2 ("NFS") nested in its section
    ord  OE-<test_id>                     e.g. OE-13 (hangs from its panel)
  ord  OE-<test_id>                       tests not in a panel hang from the section
```

Because `procedure_type` allows only **one parent per row**, a test hangs either
from its panel or (if it belongs to no panel) directly from its section group.
The LOINC column is stored in `procedure_type.standard_code` as `LOINC:<code>`.
As a convenience, each test also gets a `mod_openelis_code_mapping` row
(`OE-<test_id>` → `<test_id>`), so imported tests are immediately sendable.
Re-importing updates rows in place (no duplicates).

> ⚠️ In production the CSVs must be placed in the **same folder as the deployed
> `openelis_config.php`** (i.e. `<root>/public/modules/openelis/`), because the
> importer reads them relative to the script directory. In dev that is
> `oe-module-openelis-interface/public/`.

### OpenELIS FHIR Capability Reference

OpenELIS Global 2 (Development-Class) exposes a subset of HAPI FHIR R4 resources:
`DiagnosticReport, Observation, Organization, Patient, Practitioner,
ServiceRequest, Specimen`. It does **not** expose a test-catalog resource
(`ObservationDefinition` is unknown; `Observation`/`ServiceRequest` are used for
results/orders only), which is why the catalog is fetched via the REST provider
above.

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
