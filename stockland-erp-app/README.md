# Stockland Ethiopia ERP 16.1.63 — In-Place Corrective Release Candidate

**Release status: locally source/archive/static-QA verified; controlled staging candidate; not certified for live production.** This update is built from the validated GitHub source and carries the schema-gate health fixes, a truthful read-only setup diagnostic, and the Owner purchase-order capability re-sync. It preserves the cumulative ERP workflows and the exact 49-table contract. It is structured for clean WordPress installation or in-place update without adding business tables. Staging activation and live data reconciliation remain required.

## 16.1.53 change

- Corrects the tablet breakpoint (576–991 CSS pixels): the menu/brand stay on the first header row; action buttons live in a second horizontally scrollable row rather than overlapping, stacking, or pushing each other out of the viewport.
- Preserves the permanent left navigation on desktop and enables the hamburger menu below 992 CSS pixels.
- Makes the mobile/tablet menu close reliably from Escape, backdrop, close button and item selection; resets `aria-expanded` and the body scroll lock; restores keyboard focus; traps focus while the navigation dialog is open; and closes the mobile overlay if a resize returns to desktop.
- Allows the navigation panel to scroll on touch devices without disabling vertical touch scrolling.
- Corrects the dashboard quick-action parent that accidentally placed its heading and action grid into sibling CSS-grid columns; desktop quick actions now render as readable two-column cards, while small phones use one column.
- Restores high-contrast background/text on the EXIT link, which was rendered as white text on a white surface after the premium button reset.
- No procurement/accounting behavior, REST business payload, role authority, database DDL, or 49-table contract was intentionally changed by this menu-only patch.

## Purchasing and ledger controls carried forward

External supplier supply must follow Management entry/submission → separate active Owner approval → Central Warehouse Manager's actual quantity acceptance. Branch/Goro destinations cannot receive external supplier POs through this path. The free-form supplier receipt posting route is disabled; legacy pending manual receipts are reconciliation-only. Partial receipts remain against the same PO, and only accepted quantities post to inventory. Request keys, PO/line locks, inventory ledger writes, PLEDGER/GL journals and audit evidence are retained within the transactional workflow. Invoice matching and payment allocations remain tied to the approved PO/receipt path. The diagnostic integrity scan is read-only; it does not add foreign keys or repair old rows.

## 16.1.63 corrective release scope

- Deep LIVE READY now includes the accounting and HR/payroll schema-upgrade gates that control operational route registration.
- Setup diagnostics remain available to authorized roles during schema repair, show verified schema gaps, and explicitly state that the deep integrity audit has not run.
- Health diagnostics use a read-only snapshot and do not clear setup-error options.
- Accounting metadata query errors fail closed rather than being treated as absent schema.
- WordPress role synchronization repairs a missing Owner purchase-order approval capability or a wrongly granted Management approval capability even if the stored role-capability marker is stale.
- Database version remains 16.1.1; no business table, DDL migration, demo transaction, inventory adjustment or historical payroll rewrite is introduced.

## Local verification

- `php tools/qa/run-static-qa.php` — all local static/regression gates pass.
- PHP syntax checks, JavaScript syntax checks, CSS source/bundle consistency and ZIP integrity pass.
- A Chromium-based local browser smoke test ran the actual `assets/app.js`, `assets/app.min.css` and bundled Bootstrap against **mocked read-only API responses** at widths 375, 576, 768, 900, 991, 992, 1024, 1100, 1280 and 1440 CSS pixels. The mobile/tablet hamburger-open, Escape cleanup, focus restoration, navigation selection and desktop sidebar/header separation checks passed at each applicable breakpoint. The expanded suite completed 101/101 mocked browser checks, including dashboard quick-card sizing and EXIT contrast at desktop sizes. This is not an authenticated live-site test.
- No live website or live database was accessed or changed. Actual host role checks, MySQL locks/concurrency, physical FK inventory, receipt-to-stock-to-PLEDGER reconciliation and backup/rollback acceptance remain required on an isolated staging clone.

## Install safely

1. Back up the plugin files and database; make an isolated staging clone first.
2. Upload this single ZIP through WordPress Plugins → Add New Plugin → Upload Plugin; do not install intermediate versions separately.
3. Confirm only one Stockland ERP plugin copy is active and that the version reads 16.1.63.
4. Test at real device widths. On tablets, swipe the second header row horizontally when a user action is beyond the visible strip. On phones, test the menu open/close and item navigation.
5. Before production, complete the purchasing approval/receiving/concurrent retry checks and review the read-only integrity scan. Preserve a known rollback point.

This package uses the official local Bootstrap CSS 5.3.8 asset verified against its upstream Git blob and documented SRI; no CDN runtime dependency is added. This candidate is staging-only, not production-certified.


## 16.1.57 integration notes

- Preserves the 16.1.53 responsive mobile/tablet menu and header, desktop quick-action and EXIT contrast fixes, prior import/workflow authority controls, and the 49-table schema contract.
- Merges the 16.1.54 quick health endpoint. Quick service/schema checks explicitly state `QUICK HEALTH ONLY`; deferred audit/stock/PLEDGER/AP-AR/GL/reservation checks are not green or certified.
- Merges the 16.1.55 namespace-aware XLSX workbook/sheet relationship discovery fix, including root-absolute worksheet targets.
- Database schema constants are intentionally unchanged. No live deployment or live database migration occurred during package assembly.
- The ZIP is a staging candidate for both fresh install and upgrade; fresh WordPress setup and an in-place update from the exact plugin bytes installed on the owner-provided WordPress site must be tested on staging before production use. The target was previously reported as 16.1.62; confirm actual site version and file identity during acceptance.


## 16.1.57 approval and reconciliation acceptance notes

- Finance and procurement entry points are role-restricted server-side, not just hidden by the interface. Management prepares supplier invoices and manual payment requests; the Owner has the independent final decision; the Warehouse Manager records actual accepted quantity for approved Central Warehouse supply.
- Branch expenses from an authorized Branch Manager/Management user stay `PENDING`; the separate Owner decision posts a balanced GL journal only after authorization. Rejection requires a meaningful reason. Audit and Owner/Management notifications participate in the transaction.
- Payroll preparation and submission are Management-only. Owner approval validates the current statutory rule version, line/component evidence and debit/credit totals before GL posting; payroll payment is another Owner-only step.
- Goods receipt posts inventory and input VAT against GRNI. Approval of the matched supplier invoice clears GRNI to AP for the validated invoice total; the invoice approval must not debit input VAT a second time. Payment is allocated against posted invoices and has a stable request key to prevent duplicate payments on retry.
- System Health's standard button remains fast and read-only. `RUN DEEP INTEGRITY AUDIT` explicitly opts into full-table checks, including orphan relationships, ledger journal balance, PO/receipt/stock movement, AP/GRNI, supplier payment allocation and PLEDGER source-journal cross-checks. Any unknown query is displayed as incomplete, never counted as zero.
- Fresh installation must begin with schema and configuration only: no fake sales, stock, payroll, orders or customer transactions are seeded. Fresh activation and the 16.1.54 in-place update must be exercised on a disposable WordPress staging copy before production.

## Live certification limits

The ZIP, source, static QA and archive can be verified locally, but the builder has no authenticated access to the target WordPress host or database. No live-data deep scan, concurrency test against MariaDB, fresh WordPress activation, or update against the exact production plugin bytes was performed. This artifact is a comprehensive release candidate, not an assertion that existing live records are reconciled. Back up production and use staging for acceptance first.
