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
│       └── 🗄️ CatalogImportService.php   # Catalog REST → procedure_type + mappings (per provider)
│
├── 📂 public/                            # ⭐ Web scripts — copied to <openemr_root>/public/modules/openelis/
│   ├── 🖥️ admin_mapping.php              # Admin UI for code mapping CRUD
│   ├── 🖥️ pending_orders.php             # Pending orders + Send-to-OpenELIS UI
│   ├── 🖥️ send_order_action.php          # AJAX endpoint (POST → JSON)
│   ├── 🖥️ catalog_import.php             # Bulk catalog import (preview + confirm)
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

4. After updating the module's `public/` folder (scripts), re-copy its contents
   to the root `public/modules/openelis/` folder so the deployed version stays in
   sync.

### URL map (production)

| Script | Production URL |
|--------|----------------|
| `send_order_action.php` | `https://hcd.origen.ar/public/modules/openelis/send_order_action.php` |
| `pending_orders.php`    | `https://hcd.origen.ar/public/modules/openelis/pending_orders.php` |
| `admin_mapping.php`     | `https://hcd.origen.ar/public/modules/openelis/admin_mapping.php` |
| `catalog_import.php`    | `https://hcd.origen.ar/public/modules/openelis/catalog_import.php` |

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
   - The "OpenELIS" submenu (Import Catalog, Pending Orders, Code Mapping) will appear under the Lab section

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
5. Extracts the **sample type** (`sampleType`) the REST payload carries per test
   and resolves it against the once-only translation table
   `mod_openelis_specimen_map` (below). The resolved SNOMED code is written to
   the mapping's `snomed_specimen` automatically. Sample types with no code yet
   are registered in the table (with `snomed_code = NULL`) and reported in the
   import summary under **"Sample types without SNOMED"** so they can be curated
   a single time.
6. **Provider suffix in the visible name**: every imported `grp`/`ord` row is
   shown as `{Name} · {Lab}` (e.g. `Hemograma · Laboratorio Central`). Because
   each provider points at a **different OpenELIS**, the suffix makes the same
   analysis ordered from different labs distinguishable in OpenEMR's native
   procedure picker. The mapping's `openemr_procedure_name` and the autosuggest
   mirror keep the **clean name** (reports/results never render the suffix).
7. **Reconciliation (deactivate, never delete)**: on sync, provider-owned rows
   (`OE{p}-T*` ords and `OEP{p}-*` grps) that the catalog no longer references
   go to `activity = 0` and their auto mapping drops to `is_active = 0`. If a
   test/panel comes back it is reactivated automatically (`activity = 1`, active
   mapping). Both transitions (deactivation and reactivation) are **reported in
   the summary** — nothing happens silently. A test that **moves panels** is
   re-hung automatically under its new panel (`parent` is reassigned every sync;
   it can never be orphaned while it exists). Safeguards: reconciliation only
   runs when the read actually saw panels **and** tests; if any panel returned an
   empty member list (possible transient failure) the test-deactivation pass is
   skipped. A transient catalog error never deactivates pre-existing rows.

### 🧬 `mod_openelis_specimen_map` — sample type → SNOMED (once only)

OpenELIS exposes each test's sample type **by name only** (e.g. `Whole Blood`,
`Serum`) — it never provides the SNOMED code. To avoid typing SNOMED per test,
this small table translates the *name* to the SNOMED-CT concept and the importer
applies it to every test automatically:

| Column | Description |
|--------|-------------|
| `sample_type` | Sample type name exactly as the catalog returns it |
| `snomed_code` | SNOMED-CT specimen concept (e.g. `119297000` = blood specimen). `NULL` = not curated yet |

Seeded with the common standard concepts (`Whole Blood` → `119297000`,
`Serum` → `119364003`, `Plasma` → `119361006`, `Urine`/`Urines` → `122575006`);
adjust/add rows to match your catalog's real sample types. Matching is **exact**
(no normalization), so a spelling variant ("Urines" with an 's', seen in real
payloads) is its own row; any sample type not seeded is auto-registered with
`snomed_code = NULL` and reported under `specimen_unmapped` in the import
summary. OpenEMR's installed SNOMED
(`codes` + `code_types`, `ct_key` `SNOMED-CT`/`SNOMED`) is useful to **validate
and describe** any code you add (it does not translate names automatically — the
name→concept relation is semantic and is curated once here).

The page provides, **per provider**, a **Preview** (dry-run, no writes) and an
**Update tests** button that syncs that lab's own OpenELIS catalog, in addition to
the classic selector/confirm flow. A provider without catalog credentials (e.g. a
manual LAB01) is shown with the **"Manual / no OpenELIS"** badge and no buttons.
The whole import for **one provider** runs inside a single transaction.
`admin_mapping.php` remains the manual fine-tuning path and coexists (its rows
default to `provider_id = 0`, `import_source = 'manual'`).

### 🔧 Admin Interface

Access via **Lab → OpenELIS → Code Mapping** (requires `admin/super` ACL).

Features:
- 📋 List all active OpenEMR procedures (`procedure_type = 'ord'`), excluding
  **imaging** studies (via `order_type_name`/`procedure_type_name`)
- 🔍 Search by name, code, or standard (CPT4, SNOMED, LOINC)
- ➕ Assign new mappings with a **searchable picker** over the local mirror
  `mod_openelis_test_catalog`: type a name or ID, click a match, and the OpenELIS
  test ID + name are filled in
- ⚡ Auto-fill: the **LOINC** field is pre-suggested from
  `procedure_type.standard_code` (regex `LOINC:xxxx`), and picking a test whose
  sample type is known auto-completes **SNOMED specimen** from
  `mod_openelis_specimen_map` (when empty)
- ✏️ Edit existing mappings
- 🔄 Toggle active/inactive status
- 📄 Paginated results (20 per page)

### 💻 API Usage

#### Resolve a single mapping

```php
use OpenEMR\Modules\OpenElis\CodeMappingService;

// Mappings are scoped per lab: always pass the target provider
// (procedure_providers.ppid). A provider_id = 0 row (legacy/unassigned)
// acts as the fallback when the provider has no dedicated mapping.
$labId = 4; // procedure_providers.ppid of the OpenELIS lab

// Returns openelis_test_id or null
$elisTestId = CodeMappingService::resolveOpenElisTestId('GLUC-001', $labId);

// Returns openelis_test_id or falls back to the original code
$elisTestId = CodeMappingService::resolveWithFallback('GLUC-001', $labId);
// If mapped: returns "42"
// If not mapped: returns "GLUC-001"
```

#### Batch resolution

```php
$labId = 4; // procedure_providers.ppid of the target lab
$procedureCodes = ['GLUC-001', 'HEMO-002', 'BIO-003'];

foreach ($procedureCodes as $code) {
    $elisId = CodeMappingService::resolveOpenElisTestId($code, $labId);
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

## 📥 Result reception (Lab results back OpenELIS → OpenEMR)

Results are pulled **on demand** (buttons) — no polling yet.

### Flow (per order)

1. When an order is sent (`send_order_action.php?action=send`), each test line
   stores the FHIR `ServiceRequest/<uuid>` that OpenELIS assigned
   (`procedure_order_code.mod_openelis_service_request_id`) and the order stores
   the created patient reference (`procedure_order.mod_openelis_patient_ref`).
2. `pending_orders.php` shows a **Results** button per sent order and a global
   **Check Results (All)** button. Both POST to `send_order_action.php` with
   `action=check_results` (one order) or `action=check_results_all` (every order
   with pending results, one HTTP client per provider).
3. `ResultSyncService::syncOrderResults($orderId)`:
   - verifies the provider is `WS` + has catalog credentials;
   - verifies patient identity — `GET Patient/<ref>` and checks that the
     OpenELIS `nationalId` equals OpenEMR `patient_data.pubpid`; on mismatch the
     order is rejected (**nothing is imported**);
   - for each pending test line queries
     `DiagnosticReport?based-on=ServiceRequest/<uuid>`;
   - `ResultMapper::toOpenEmr()` maps each report + its observations into the
     native OpenEMR lab tables `procedure_report` / `procedure_result`
     (so results appear in the standard results UI);
   - marks the line `mod_openelis_results_status = 'downloaded'`
     (idempotent re-runs; lines already downloaded are skipped).

### Status column values

`procedure_order_code.mod_openelis_results_status`: `pending` (set at send time),
`downloaded`, `error`.

### Observations/edge cases

- Report subject must equal the order's verified `Patient/<ref>`; anything else
  is skipped with an `error_log` entry.
- `DiagnosticReport.status` final/amended/corrected → `report_status = 'complete'`;
  other statuses → `received`.
- `Observation` values: `valueQuantity` (numeric `N`), `valueString` (string `S`,
  long → `L`), `valueCodeableConcept`, `valueInteger`. Reference ranges from
  `referenceRange[].low/high/text`; abnormal flags from `interpretation` codings
  (H/HH→high, L/LL→low, A/AA→yes).
- Use `public/probe_results_api.php` on a test server to inspect the actual
  payloads (`?ppid=<id>` lists recent reports; `?ppid=<id>&sr=ServiceRequest/<uuid>`
  shows the reports for one test + their observations) before relying on the
  mapping.

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

- The local mirror table `mod_openelis_test_catalog` is kept fresh by the
  catalog import (`public/catalog_import.php` / `CatalogImportService`), which
  upserts every imported test by its OpenELIS id (including `sample_type` when
  the API provides it).
- The mapping page (`public/admin_mapping.php`) reads that local mirror to
  autosuggest the OpenELIS test id/name when assigning a mapping — no per-keystroke
  API calls.
- LOINC is not provided by this endpoint, so it is optional / entered manually
  (or pre-suggested from `procedure_type.standard_code` in the mapping form).
- The prior design (reading OpenELIS's `clinlims.*` PostgreSQL tables directly)
  was abandoned because the lab does not share database credentials.

### Importing the catalog (was CSV import)

Earlier versions imported the catalog from two CSV export files dropped next to
the deployed scripts (`catalog.csv` / `panels.csv`, via
`src/Service/ProcedureCatalogImporter.php`) from the **OpenELIS Settings** page.
That page, the CSV importer class and the sample files have been **removed**.
The REST-based import in [`catalog_import.php`](#-catalog-import-rest--catalog_importphp)
covers the same goal (panels + ordered tests → `procedure_type` tree +
`mod_openelis_code_mapping`) using each provider's ADMIN catalog credentials,
without requiring database access or CSV exports.

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
