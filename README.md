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
| 🧪 Lab Order Sync | ✅ Done | Send lab orders from OpenEMR to OpenELIS (Specimen + ServiceRequest + Task) |
| 👤 Patient Sync | ✅ Done | Synchronize patient + ordering Practitioner (FHIR Patient/Practitioner) |
| 📊 Result Retrieval | 🚧 Partial | Pull DiagnosticReport/Observation on demand — **blocked** by a store read-by-ID issue (see Troubleshooting) |
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
│  │  • Order Sync (done)      │  │         │  │  • Patient Management     │  │
│  │  • Patient Sync (done)    │  │         │  │  • Order Management       │  │
│  │  • Results Webhook        │  │         │  │  • Result Reporting       │  │
│  └───────────────────────────┘  │         │  └───────────────────────────┘  │
└─────────────────────────────────┘         └─────────────────────────────────┘
```

> ⚙️ **Deployment topology (Origen setup):** OpenELIS runs **two Tomcats**
> sharing one PostgreSQL database (`clinlims` schema):
>
> | Container | Purpose | Ports visible on host |
> |-----------|---------|----------------------|
> | `openelisglobal-webapp` | Application UI + embedded FHIR writer (`/OpenELIS-Global/fhir`) | `127.0.0.1:8443` (HTTPS) |
> | `external-fhir-api` | Stand-alone **FHIR store** (`fhir.openelis.org`) that this module reads/writes | `127.0.0.1:8444` (HTTPS, mutual TLS) + `127.0.0.1:8081` (HTTP, restored) |
>
> The module's provider config (`procedure_providers.remote_host`) points at the
> FHIR store's HTTP base URL: `http://127.0.0.1:8081/fhir/`.

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
│   │   └── 🔌 CatalogApiClient.php       # catalog REST client (GET /rest/TestCatalog, admin catalog user)
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
│   └── 📄 find_order_popup.php.patch.txt # Patch (8.2.0): strict per-provider + order-type scoping in the Procedure Picker
│   └── 📂 openemr/                       # COMPLETE patched files (production-ready)
│       └── 📂 interface/forms/procedure_order/common.php
│       └── 📂 interface/orders/procedure_provider_edit.php
│       └── 📂 interface/orders/find_order_popup.php
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
| `openelis_panel_id` | VARCHAR(20) | Section grp code the test hangs from (`OEP..-SEC-..`, informational) | 
| `openelis_panel_name` | VARCHAR(255) | Section/panel display name (informational) |
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
OpenELIS REST API in **one call** — `GET /OpenELIS-Global/rest/TestCatalog`
(requires an **OpenELIS ADMIN** user, configured per provider as
`procedure_providers.mod_openelis_catalog_login` / `mod_openelis_catalog_password`
— never the operational Analyser Import user; edited on the native Procedure
Providers form, see `patches/procedure_provider_edit.php`). Only providers that
carry the catalog ADMIN login (the OpenELIS-lab marker, same as `pending_orders`
/ `probe_results_api`) are listed on the import page — PACS or other non-OpenELIS
providers never appear. The importer:

1. Reads the **whole catalog** (a single non-paginated document; every test,
   active and inactive) and keeps only the **active** tests (`active` =
   "Active"). The API does **not** expose panels as a list — there is no
   `/rest/test-catalog/panels*` endpoint (those paths answer Tomcat 404), so the
   import is **test-centric**.
2. Groups tests under one `grp` per OpenELIS test **section**
   (`testUnit`); the panel name each test reports is kept as informational
   mapping data only.
3. Creates/updates `procedure_type` rows per provider:
   ```
   grp  OEP{providerId}-SEC-{hash8}  e.g. OEP2-SEC-1a2b3c4d (parent = 0, top-level;
                                     hash8 = first 8 hex of md5(lowercase section name),
                                     stable across runs)
     ord OE{providerId}-T{testId}    e.g. OE2-T42   (hangs from its section via parent)
   ```
   `parent` references the section group's **`procedure_type_id`** (AUTO_INCREMENT
   primary key), not its code. Codes are deterministic per
   `(provider, test)`/`(provider, section)`, so re-importing is idempotent and
   never collides between labs. Names are truncated to the column's 63 chars
   without splitting words; uniqueness depends **only** on `procedure_code`.
4. Generates one `mod_openelis_code_mapping` row per imported test with
   `import_source = 'catalog_import'` and the LOINC code when the catalog
   provides it. `openelis_panel_id` carries the section group code and
   `openelis_panel_name` the panel display string (informational in both cases).
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
   test/section comes back it is reactivated automatically (`activity = 1`, active
   mapping). Both transitions (deactivation and reactivation) are **reported in
   the summary** — nothing happens silently. A test that **moves sections** is
   re-hung automatically under its new section (`parent` is reassigned every sync;
   it can never be orphaned while it exists). Safeguards: reconciliation only
   runs when the read actually saw sections **and** tests; a failed/empty catalog
   read never deactivates pre-existing rows. Group rows left over from the **old
   panel-based** scheme (`OEP{p}-{panelId}`) that no section matches are
   deactivated the same way on the first run of the test-centric importer.

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

## 📤 Order send (OpenEMR → OpenELIS)

1. `send_order_action.php?action=send` calls `OrderSyncService::sendOrderToOpenElis()`:
   - syncs the patient and the ordering provider to OpenELIS (Patient / Practitioner);
   - for each test line creates a **Specimen** and a **ServiceRequest** (with
     `code.system = http://openelis-global.org/testId`, matched through
     `mod_openelis_code_mapping`) in the OpenELIS FHIR store;
   - **publishes a `Task`** (`status=requested`, `intent=order`,
     `basedOn` → the created ServiceRequests, `for` → the Patient,
     `owner` → the ordering Practitioner, or the ref configured via the
     `openelis_task_owner` config key). This is the resource OpenELIS polls
     to surface the order in its **Electronic Orders** queue — without it the
     ServiceRequest sits in the FHIR store but is never imported.
   - stores refs on the order: `mod_openelis_order_id` (primary ServiceRequest),
     `mod_openelis_task_id` (Task), `mod_openelis_patient_ref` (Patient).
2. The order is marked `mod_openelis_sync_status = 'sent'`. If the ServiceRequests
   were created but the Task failed, the status is `'error'` and the response tells
   you the Task is required.

### OpenELIS side: required config for EMR-LIS import

The import is driven by OpenELIS properties (not the UI — Spring ignores UI
changes until the webapp is restarted). In `common.properties` (mounted as a
Docker secret, e.g. `/run/secrets/common.properties`) set at least:

```properties
# Where to poll inbound orders. The module publishes everything (ServiceRequest + Task)
# to OpenELIS's own co-resident FHIR, so this is the same base URL:
org.openelisglobal.remote.source.uri=http://127.0.0.1:8081/fhir/
org.openelisglobal.remote.source.updateStatus=true
# Owner filter for Task polling: must equal the Practitioner reference the module
# synchronizes/creates for the ordering provider (Practitioner/<uuid>).
# Use GET <uri>/Practitioner?identifier=<npi> to find it.
org.openelisglobal.remote.source.identifier=Practitioner/<ordering-provider-uuid>
org.openelisglobal.task.useBasedOn=true
# Subscribed resources (the Task is the essential one):
org.openelisglobal.fhir.subscriber.resources=Task,Patient,ServiceRequest,DiagnosticReport,Observation,Specimen,Practitioner,Encounter
```

Then restart the OpenELIS webapp and enable **External orders** in
Administration → External Orders.

> **Task owner override.** OpenELIS polls Tasks whose `owner` equals the single
> practitioner ref above (`remote.source.identifier`). By default the module
> publishes the Task under the ordering provider's practitioner ref — which only
> matches that filter if the deployment created/uses that exact practitioner.
> To decouple the Task owner from the ordering provider, set the module config
> key `openelis_task_owner` (in the `mod_openelis_config` table) to the same
> ref used on the OpenELIS side, e.g.:
>
> ```sql
> INSERT INTO mod_openelis_config (cfg_name, cfg_value)
> VALUES ('openelis_task_owner', 'Practitioner/2181365d-7e4d-5d47-a18d-3da3fe37e8af');
> ```
>
> The value can be a full `Practitioner/<uuid>` ref or a bare uuid (the module
> prefixes the `Practitioner/` part for you).

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

- Stored rows receive native OpenEMR UUIDs: each inserted `procedure_report` /
  `procedure_result` row gets its `uuid` (binary(16)) populated right after the
  insert via `UuidRegistry::createMissingUuidForRow()` — the same registry the
  core uses, so UUID-based lookups (API/FHIR, `ProcedureService`) work as with
  native results.
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

### Troubleshooting (FHIR store)

Symptoms, root causes and fixes found during integration:

- **`cURL error: Recv failure: Connection reset by peer` on every order.** The
  store's Tomcat (`external-fhir-api`) had **no HTTP listener**. Its
  `/opt/bitnami/tomcat/conf/server.xml` ships with the `8080` connector
  commented out — only a mutual-TLS HTTPS `8443` connector is active
  (`clientAuth="true"`). The module talks plain HTTP
  (`http://127.0.0.1:8081/fhir/`), so it must be restored:
  ```xml
  <Connector port="8080" protocol="HTTP/1.1" connectionTimeout="20000" redirectPort="8443"/>
  ```
  then `docker restart external-fhir-api`. Verify:
  `curl -s 'http://127.0.0.1:8081/fhir/metadata'` → HTTP 200.

- **`HAPI-2001: Resource <type>/<id> is not known` when reading a resource by
  ID, even though it exists in `hfj_resource`.** The resources were created by
  the OpenELIS **webapp's own FHIR writer** (numeric, client-assigned IDs such
  as `ServiceRequest/6159`) and live in the shared PostgreSQL
  (`hfj_resource`/`hfj_res_ver`, `res_deleted_at` NULL). Search works
  (`/DiagnosticReport?_count=5` returns them), but **read-by-ID (GET) fails**.
  Getting an ID to resolve requires the resource to be created through the
  store's own API (as the webapp poll workflow does), so the read path maps ID
  → internal PID. Workaround: keep the store as the single writer (webapp
  `common.properties` → `remote.source.uri=http://external-fhir-api:8080/fhir/`
  + `fhir.subscriber.resources`) so results are persisted by the store itself.

- **`HAPI-0960` / "ID in the past not assignable by client" when creating
  Patient/Practitioner.** The store enforces `client_id_strategy`; the webapp
  image's default rejects client-assigned numeric IDs. Set
  `HAPI_FHIR_CLIENT_ID_STRATEGY=ANY` on the `fhir.openelis.org` service in
  `docker-compose.yml` and recreate the container. Verify by POSTing a resource
  with a numeric ID → expect HTTP 201.

- **HTTP 000/refused from the OpenEMR host on `:8444`.** That port is the
  store's HTTPS side with **mutual TLS** — it needs a client certificate
  (the `/etc/openelis-global/keystore` PKCS12, legacy format; keytool must
  convert it for OpenSSL/curl use). Prefer the HTTP `:8081` endpoint for module
  traffic.

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

The catalog is read over the HTTPS REST API (the lab provides only an API
user/password, not database credentials); both endpoints use Basic Auth with an
OpenELIS user carrying the **ADMIN** role:

| Endpoint | Method | Notes |
|----------|--------|-------|
| `/OpenELIS-Global/rest/TestCatalog` | GET | **Catalog import (`CatalogApiClient`)** — returns the WHOLE test catalog in one document (`{ testCatalogList, testSectionList }`). Per test: id, localized name, section (`testUnit`), sampleType, panel (display string), loinc, uom, active ("Active"/"Not active"), orderable, sort order, result limits / dictionary values. No pagination and no panels list. |
| `/OpenELIS-Global/rest/TestNamesProvider?testId={id}` | GET | Legacy single-name probe (`OpenElisCatalog`), kept only as fallback/reference: returns one test's name for a single numeric id. `testId=all` → HTTP 500, so id-probing is one id at a time. Does NOT return LOINC. |

- OpenELIS does **not** expose panels as a list over REST: every
  `/OpenELIS-Global/rest/test-catalog/panels*` path answers the Tomcat **404**
  you see when a client assumes pa panel endpoint. Panel membership only appears
  as a display string per test, which is why the import groups by section.
- The provider's `remote_host` normally points at the **FHIR store**
  (external-fhir-api ports 8080/8081/8444, e.g. `http://127.0.0.1:8081/fhir/`),
  which answers **only FHIR** paths. The REST webapp lives on its own origin
  (`https://127.0.0.1:8443`, Host `elis.origen.ar`), so `CatalogApiClient`
  redirects external-FHIR ports to the webapp origin — the same rule
  `PatientManagementClient` already used.

- The local mirror table `mod_openelis_test_catalog` is kept fresh by the
  catalog import (`public/catalog_import.php` / `CatalogImportService`), which
  upserts every imported test by its OpenELIS id (including `sample_type` when
  the API provides it).
- The mapping page (`public/admin_mapping.php`) reads that local mirror to
  autosuggest the OpenELIS test id/name when assigning a mapping — no per-keystroke
  API calls.
- LOINC comes from `GET /rest/TestCatalog` when it is configured on the OpenELIS
  test; otherwise it stays optional — entered manually (or pre-suggested from
  `procedure_type.standard_code` in the mapping form).
- The prior design (reading OpenELIS's `clinlims.*` PostgreSQL tables directly)
  was abandoned because the lab does not share database credentials.

### Importing the catalog (was CSV import)

Earlier versions imported the catalog from two CSV export files dropped next to
the deployed scripts (`catalog.csv` / `panels.csv`, via
`src/Service/ProcedureCatalogImporter.php`) from the **OpenELIS Settings** page.
That page, the CSV importer class and the sample files have been **removed**.
The REST-based import in [`catalog_import.php`](#-catalog-import-rest--catalog_importphp)
covers the same goal (active tests grouped by test section → `procedure_type`
tree + `mod_openelis_code_mapping`) using each provider's ADMIN catalog
credentials, without requiring database access or CSV exports.

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
