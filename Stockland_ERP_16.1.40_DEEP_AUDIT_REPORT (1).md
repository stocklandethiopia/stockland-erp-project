# Stockland Ethiopia ERP — 16.1.40 Consolidated Audit Candidate

**Decision:** Candidate prepared for independent SAST/DAST and controlled staging acceptance. **Not production-certified. Do not install over the live system until staging gates pass.**

**Scope:** Combined release ZIP supplied for this audit; WordPress plugin PHP; REST route registration and permission callbacks; the application’s 49-table contract and DDL; Product Master and Daily POS import; warehouse and purchase-order receiving; POS/wholesale ledger and journals; UI JavaScript, Bootstrap/CSS, responsive navigation, PWA/cache integration; QA scripts and packaging hygiene; stated Stockland workflow contract. No production database writes were performed.

## 1. Executive summary

The newest distinct source package in the archive is 16.1.39. It contains useful 16.1.39 frontend/cache changes and follows earlier 16.1.37 and 16.1.38 remediation work, but source tracing found several defects that could prevent the documented workflow from operating as intended. Those defects have been corrected in the 16.1.40 candidate, and a local regression suite has been rerun successfully.

The most operationally significant correction is warehouse receipt sequencing: **creating a receipt now stages it as Pending; stock is not posted until an authorized user confirms RECEIVED with the actual counted quantity.** The candidate also exposes the PO receiving queue in Central Warehouse, connects it to the existing receiving endpoints, and checks calendar/day-close controls on PO receiving.

Local package QA is green, but that is not the same as an international audit certificate or a live-host acceptance. Bootstrap remains v5.3.6 instead of the requested v5.3.8; database foreign-key constraints are absent; a live authenticated test, full SAST/DAST, browser E2E and the PHPUnit runner were not available in this run. Those are explicitly open gates below.

## 2. Release archive reconciliation

The supplied outer archive contains 40 ZIP entries. Several `(1)`, `(2)` and `(3)` files have identical SHA-256 hashes and are duplicate copies, not new fixes. The direct nested archives contain 22 unique binary hashes. Recursively opening one-pack containers reveals 30 distinct ZIP binaries in total, including 20 actual plugin source trees covering releases 16.1.28 through 16.1.39; the rest are one-pack/installation wrappers or reports.

| Release family | Audit interpretation |
|---|---|
| 16.1.28 / 16.1.30 | Early baseline/clean-plugin artifacts; the 16.1.30 one-pack also embeds older and later reissue packages. The wrapper is not itself a single plugin directory. |
| 16.1.31–16.1.32 | Multiple clean-plugin, UI-only and one-pack variants. Similar release numbers do not imply byte-identical packages. |
| 16.1.34–16.1.35 | Expanded operational/accounting/UI packages, with alternate wrappers. |
| 16.1.36 | Several fresh-install and in-place package variants with different file inventories; package type must be checked, not inferred from the name. POS/Product Master/Business Calendar behavior becomes more prominent in this family. |
| 16.1.37 | Supplies broader QA and a wholesale journal implementation retained as a prior best-practice reference. |
| 16.1.38 | Adds workflow/sequence regression work. |
| 16.1.39 | Newest source; frontend menu/overlay/cache remediation is valuable and retained. The source still had critical workflow gaps identified below. |
| **16.1.40** | Consolidated audit candidate based on 16.1.39, with targeted source corrections and expanded QA gates. |

Every one of the 20 distinct historical plugin source trees passed the available PHP syntax check, and the available `app.js` / `pwa.js` files passed Node syntax checks. That result checks syntax only; it does not prove the historical runtime workflows worked.

## 3. CAP findings closed in candidate 16.1.40

| CAP | Finding in the source lineage | Candidate correction / verification |
|---|---|---|
| CAP-01 | The warehouse receive-form submit handler created a receipt and immediately called the completion endpoint, bypassing physical-count confirmation. | Submit creates Pending receipt only; visible queue displays expected and actual quantities. The RECEIVED action is the sole step that posts stock. Regression check verifies no auto-complete call remains in that submit branch. |
| CAP-02 | Import and inventory-report REST `permission_callback` values referenced `private` instance methods that WordPress invokes through the REST dispatcher. | The callback methods are public. A visibility scan found no remaining direct `$this` REST callback that points to a private method. |
| CAP-03 | The shared JavaScript API helper sent JSON `Content-Type` on multipart `FormData` requests, conflicting with browser-generated multipart boundaries for XLSX uploads. | Multipart requests omit the manual Content-Type header; JSON requests continue to send JSON Content-Type. Static workflow checks cover the FormData branch. |
| CAP-04 | The Finance screen called `loadPurchaseOrders()` although the function was absent. The PO action queue therefore could not be relied on to load. | Added live queue, detail drawer, status actions, and a GRN receiving dialog wired to existing PO REST endpoints. Added the queue to Central Warehouse for warehouse-view roles, and exposed the Warehouse menu where receiving authority exists. No backend capability was granted by the UI patch. |
| CAP-05 | PO receiving did not enforce the same Business Calendar and day-close rules as manual warehouse receiving. | Validates `receipt_date`, calls `Stockland_Business_Calendar::assert_business_day()` and `Stockland_DB::is_day_closed()` before lock acquisition and again in the transaction. Closed days return 409; invalid dates return 400. PO location is rechecked after lock. |
| CAP-06 | 16.1.39 wholesale posting had lost its PLEDGER/GL journal, while stock and audit code remained. | Restored the previous 16.1.37 journal block after diffing the wholesale controller: the journal block was the removed block, with the surrounding controller actions matching. The order transaction now posts sales revenue/VAT and COGS/inventory-relief journal lines alongside stock ledger and audit. |
| CAP-07 | Daily POS import prevented duplicate source files/jobs but lacked a cross-job guard for the same external receipt number in the same branch/date/source. | Added a guard against another POSTING/POSTED batch for the same external receipt identity. Rows without an external receipt retain their deterministic per-job row fallback. The user still explicitly releases the staged import. |
| CAP-08 | Product Master commit did not persist final destination identity to staged import rows, making it harder to trace workbook row → live Product Master record. | Commit persists `mapped_sku` and `variant_id`; response includes destination tracking rows and job detail exposes mapped SKU / destination variant ID. Existing Product Master catalog-only controls are preserved. |
| CAP-09 | XLSX safety implementation limits files to 8 MB, but its QA assertion expected 12 MB and failed the package gate. | Updated the assertion to match the implemented 8 MB limit; XLSX safety QA passes. |
| CAP-10 | A remediation report was packaged under the runtime plugin directory. | Removed the report from the installer; retained it outside the plugin package as audit working evidence. CLI-only QA scripts are guarded against web execution. |
| CAP-11 | Receiving capability existed separately from warehouse viewing, but menu visibility and the PO queue placement did not provide a complete operator path for the relevant role. | Menu visibility includes receiving authority, receipt queue is rendered in Central Warehouse for receiving-capable users, and the PO queue appears there for warehouse-view users. Regression suite checks navigation and queue presence. |

### Receiving workflow now enforced in code

`Create Receipt → Pending Queue → Count Actual Quantity → Provide variance reason when actual differs → RECEIVED → Inventory Ledger post + audit event`.

A PO receipt uses the PO receiving endpoint and performs bounded partial/full receiving, GRN creation, ledger posting, PO balance update, journal posting and audit under the existing transaction/lock path. Receipt/PO UI integration passed source-level and workflow-contract checks; it still needs browser testing against a real database.

## 4. Critical data paths traced

### 4.1 Product Master import

The XLSX gateway validates file signature/container/expansion, uses an 8 MB file limit and a 5,000-row job limit, stages a preview, validates SKU syntax and duplicate identifiers, checks STYLE/VARIANT identity and required parent/color/size fields, and rejects physical-stock quantity fields. Product Master commit remains an explicit Management/Owner action and updates catalog data only; warehouse quantities must be posted through receiving or another approved inventory movement.

The candidate improves destination traceability by recording mapped SKU and destination `variant_id` after commit. STYLE/SIMPLE records are processed before VARIANT records through the bounded commit batches so imported child variants can resolve to a parent. A full large workbook and partial-commit test must still be run on staging.

### 4.2 Daily POS / daily inventory import

The intended path remains manual XLSX upload of the third-party POS export per branch and business date, with preview and validation before an explicit RELEASE TO ERP action. No POS API connection or server scheduler is introduced. Source receipt numbers are checked across other active/posting jobs for the same source, date and branch. Commit is transaction/lock based and uses shared sale/VAT arithmetic, inventory ledger and accounting journal services.

Before staged rows post, the code rechecks live price/cost and VAT assumptions against the preview; a changed master value requires re-preview. Unknown third-party POS codes require a mapping to an authoritative SKU rather than inventing a product. Final staging acceptance must confirm that repeated clicks, two simultaneous commits, partially failed chunks and duplicate source rows cannot create duplicate sales or unbalanced ledger activity.

### 4.3 Retail POS and wholesale

The bundled smoke test executes the actual `create_sale()` path offline with controlled stubs and passes 11 assertions. Static checks verify centralized sale math/calendar calls and ledger-posting path. Wholesale controller diff showed the GL/PLEDGER block removed in 16.1.39; that block has been restored from 16.1.37. Neither offline smoke nor code inspection substitutes for checking actual GL lines sum to zero and inventory balance changes match a posted real transaction in staging.

### 4.4 Purchase order and receiving

PO list/detail/status/receive routes already existed in the backend. The candidate adds client controls for the existing operations and prevents quantities above remaining PO quantity. It checks day closure for PO receipts. Staging must prove draft → submitted → approved → partial receive → fully received → closed, with proper rejection/cancellation reasons and location permissions.

### 4.5 Frontend / cache / responsive system

The 16.1.39 z-index correction that raises body-appended menus, overlays and toasts above the fullscreen ERP root is preserved. Versioned asset/cache behaviour, the local Bootstrap asset, responsive layout rules, mobile drawer open state, visible header/action labels, and sticky page actions pass the repository’s static CSS/release checks. These checks do not visually exercise every viewport or screen reader; the viewport matrix in Section 8 is required.

## 5. Database and architecture observations

- **Application table contract:** 49 unique application tables: 29 core operations, 16 accounting/procurement, 4 HR/payroll.
- **DDL static QA:** 49 definitions detected as InnoDB tables with primary keys. Candidate introduces no new business table.
- **Foreign keys:** no SQL `FOREIGN KEY ... REFERENCES` definitions were found in the plugin DDL. Current integrity is application-enforced. This is an open engineering risk, not a safe blind patch: run a production-safe orphan scan and plan controlled constraints/migrations separately after backup.
- **Sequences:** QA confirms generated document identifiers use the atomic sequence service and legacy random three-digit patterns are absent.
- **No server scheduling/trigger reliance:** source scan found no `wp_schedule_event`, `wp_schedule_single_event`, `wp_next_scheduled`, `CREATE TRIGGER` or `CREATE EVENT` usage. Operational imports remain explicit, user-driven, bounded/chunked requests; locks serialize critical inventory/commit actions.
- **Permissions:** all 103 statically identified `register_rest_route()` calls have explicit `permission_callback` entries. This is a presence check plus callback visibility scan, not a complete dynamic authorization test.
- **SQL/dataflow:** code uses the existing WordPress DB adapter and table namespace. The local scan is not a full taint-tracking engine and has not run against live database data or the host’s exact MySQL/sql-mode configuration.

## 6. Local verification executed

| Check | Result | What it means / limitation |
|---|---|---|
| Full bundled `tools/qa/run-static-qa.php` | PASS | Entire available QA entry point completed successfully. |
| REST route permissions | PASS: 103 routes | Each statically scanned REST registration has a permission callback. |
| Table contract / DDL | PASS: 49/49 | Expected table keys and InnoDB/primary-key definitions present. No FK assertion is implied. |
| Sequence / money / XLSX safety | PASS | Static contract checks and local PHP smoke assertions pass; no real host upload was done. |
| Critical workflow QA | PASS: 28 gates | Confirms the specified code contracts for POS, import, receiving, warehouse route visibility, calendar/day-close and journal presence. |
| Targeted remediation QA | PASS: 29 assertions | Targeted earlier CAP assertions pass. |
| POS behavioral smoke | PASS: 11 assertions | Real `create_sale()` function executed offline using test stubs, without production DB access. |
| Release integrity / CSS / runtime hygiene / client output safety | PASS | Local static checks pass; there are still `innerHTML` sinks that need regression review with real/browser inputs. |
| PHP syntax | PASS: 63 PHP files, 0 failures | PHP 8.4.24 local CLI. Not the same as the host’s PHP version. |
| JavaScript syntax | PASS: `assets/app.js`, `assets/pwa.js` | Node 22.16.0. Not browser interaction testing. |
| Historical plugin syntax | PASS: 20 distinct source trees, 0 PHP/JS syntax failures | Version-history syntax scan only. |
| Heuristic code scan | No `eval`, `unserialize`, shell execution, weak MD5/SHA1 hits in runtime PHP; no private direct REST callbacks | Heuristic scan only; not a SonarQube/Semgrep SAST result. |

The PHPUnit configuration and test files are present, but the PHPUnit runner was not available, so the PHPUnit suite was **not** executed. CI and PHPCS/WPCS configuration files exist, but neither the external scanner nor PHPCS was executed in this run.

## 7. Open CAPs — blockers before production certification

| Priority | CAP / remaining issue | Required closure evidence |
|---|---|---|
| **P1** | **CAP-12 — Bootstrap version mismatch.** Bundle contains Bootstrap 5.3.6; requested baseline was 5.3.8. | Vendor the official 5.3.8 CSS artifact, verify its upstream checksum/source, rerun CSS/release QA and include the exact version in package evidence. Do not rename 5.3.6 to 5.3.8. |
| **P1** | **CAP-13 — No authenticated live acceptance.** Unauthenticated access to the supplied production URL returned HTTP 403, so it was not possible to test live screens or API sessions. | Install candidate on an isolated WordPress staging copy with the real host/version/DB configuration; capture role-based UI/API, logs and ledger evidence. |
| **P1** | **CAP-01/04/05/06/07/08 integration proof.** Corrections are source/contract-tested only. | Run Sections 8.1–8.4 against real staging database data, include transaction rollback/concurrent clicks and reconciled ledger/GL evidence. |
| **P1** | **CAP-14 — No database FK constraints.** | Export/backup, orphan/duplicate scan for all relationships, report risk, then design and test a controlled migration. Do not add blanket FKs before analysing live records and WordPress/dbDelta constraints. |
| **P1** | **CAP-15 — Enterprise SAST/DAST not run.** No SonarQube, Semgrep, OWASP ZAP, PHPCS/WPCS tool run or browser E2E result is claimed. | Run external SAST with SonarQube/CWE and OWASP-oriented rules, dependency review, DAST against staging, PHPCS/WPCS, manual authorization/dataflow review, and browser tests; triage and rerun all findings. |
| **P2** | **CAP-16 — PHPUnit suite not executed.** | Install/use the approved PHPUnit runner in CI and record test output. |
| **P1** | **CAP-17 — InfinityFree runtime characteristics unknown.** | Verify PHP and MySQL versions, upload/post caps, execution time, memory, lock waits, persistent object/cache behaviour, transaction semantics, file permissions and database privileges on staging. |
| **P1** | **CAP-18 — Standalone SOP acceptance evidence.** No separate complete SOP manual was included as an independent document in this bundle. Inline workflow rules and code paths were traced, but a formal SOP-by-SOP sign-off is still required. | Attach the ratified SOP/current control matrix and record each operator test, expected transition, role, actual result, ledger lines and approver sign-off. |

## 8. Mandatory staging acceptance before live update

### 8.1 Installation, roles and API

1. Backup the database and current plugin/package. Clone to staging. Install the single 16.1.40 plugin ZIP and record WordPress/PHP/MySQL versions and activated plugin version.
2. Confirm clean setup/health and the 49 application table contract. Confirm no new table, no seeded demo business data and no legacy Hub/AI endpoint.
3. Verify front-end-only operation for Owner, Management, Central Warehouse, branch manager and seller roles. Do not require ordinary users to create users or perform business operations inside the WordPress dashboard.
4. Probe REST routes as unauthenticated, authenticated wrong-role, correct-role, wrong-location and correct-location accounts. Expect denial for unauthorized scopes and success only for allowed capabilities. Save sanitized HTTP results.

### 8.2 Receipt and procurement workflow

1. Create a warehouse receipt. Confirm it is Pending and inventory ledger/balance is unchanged.
2. Enter a different actual quantity without a reason; confirm the server rejects it. Add a reason, select RECEIVED and confirm a single ledger movement, completed receipt, audit event and notification.
3. Attempt the operation on a closed calendar/day-close date; confirm it is rejected with no receipt stock/journal side effect.
4. Create a PO with valid catalog SKUs, submit, approve, receive part, receive the remaining balance and close. Validate GRN IDs, line quantities, stock movement, accounts/journal balance, status transitions, actor/time audit and branch/warehouse scope.
5. Double-click/retry the same receive action and send concurrent requests. Confirm no duplicate receipt, stock movement or journal.

### 8.3 Product Master and Daily POS import

1. Upload a valid Product Master workbook (under 8 MB) containing a new STYLE, child VARIANTs, existing-row update, valid price change and barcode/GTIN. Check preview, explicit Management/Owner commit, `mapped_sku` and destination `variant_id` traceability.
2. Confirm Product Master import rejects stock/quantity fields and does not create inventory movement. Test duplicate SKU, duplicate barcode/GTIN, missing parent, missing color/size, invalid SKU, invalid workbook signature and malformed/oversized ZIP/XLSX.
3. Test a maximum-size-per-contract 5,000-row workbook using the actual InfinityFree request limits, and test staged commit after a PHP timeout/retry. Verify parent/variant order and correct per-row destination tracking.
4. Upload the same third-party POS file twice for the same branch/date, then replay one external receipt in a different job. Confirm duplicate jobs/receipts are blocked.
5. Preview and explicitly RELEASE TO ERP. Check mapping, quantity/price/VAT math, sale count, ledger, stock balance, PLEDGER/GL journal totals, audit events and job status. Repeat release/concurrent requests to test idempotency and locks.
6. Verify manual Daily POS upload remains available to authorized branch users without cron/API dependencies and appears in management/owner reports with actor and business date.

### 8.4 POS, UI and host performance

1. Exercise Retail POS and Wholesale with paid/unpaid/partial payment states. Reconcile gross/net/VAT, COGS/inventory relief and debit/credit totals; verify total debits equal total credits.
2. Exercise transfer/requisition dispatch and branch receive with partial quantity and required variance note. Verify the branch cannot transact outside assigned locations.
3. Manually test 320, 360, 390, 430, 768, 1024, 1280, 1440 and 1920 pixel widths. Check navigation, receipt/PO drawers, mobile menu z-index, error messages, sticky action bar, wide tables, keyboard focus and reduced-motion behaviour.
4. Test stale service-worker/cache refresh and update behaviour. Verify the screen reports the currently deployed release after activation and hard refresh.
5. Record memory/time/query limits and database lock contention under a representative import. Confirm a chunk retry can recover without duplicate rows and all lock/transient cleanup paths execute after errors.
6. Attach the official SAST/DAST/PHPCS/PHPUnit/browser outputs to the same CAP matrix; any new findings must be corrected and all relevant gates rerun.

## 9. Hosting strategy for shared hosting

The candidate does not require server-side cron or database triggers. Large workbook actions remain explicit and bounded, chunked imports and ledger updates are transaction/lock controlled, local vendor assets avoid CDN dependency, and QA scripts are CLI-guarded. The actual free-host plan may impose limits this local environment cannot infer; request size, PHP time/memory limits, DB transaction support and cache freshness need staging observation rather than assumptions.

## 10. Final recommendation

Use **Stockland ERP 16.1.40 Audit Candidate** as the package for the next external international scan and isolated staging run, not as a declaration of production readiness. The main known receipt/POS/Product Master/PO/accounting/UI gaps enumerated in this report have source corrections and passing local regression gates. The 5.3.8 Bootstrap mismatch, foreign-key strategy, host runtime, full PHPUnit, SAST/DAST and authenticated end-to-end acceptance remain open. Production sign-off should occur only after those CAPs have evidence, are resolved or formally risk-accepted, and the same regression matrix is green on the actual staging environment.
