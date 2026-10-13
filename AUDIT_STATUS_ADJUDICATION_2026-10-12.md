# Current GitHub status — 2026-10-13

This section supersedes the older point-in-time decisions below; those entries are retained as historical audit context.

- PR #25 for Stockland ERP 16.1.65 is **merged** into `main` at commit `e5a8054e2e71c058d8ddd7feafdb145351e0ccd6`.
- The post-merge GitHub Actions source/package gate [run 38410998164](https://github.com/stocklandethiopia/stockland-erp-project/actions/runs/38410998164) completed **SUCCESS** on that exact commit.
- The run built installer `STOCKLAND_ERP_16.1.65_FINAL_CANONICAL_BOOTSTRAP_5.3.8.zip`, SHA-256 `97188bda48e37051f9bcae4040720a63115cb1b03ad46cae2b9366a545f1d119`; [Actions artifact 11821676210](https://github.com/stocklandethiopia/stockland-erp-project/actions/runs/38410998164/artifacts/11821676210) contains the package and checksum file.
- The source gate passed PHP lint, Composer validation, PHPUnit, JavaScript syntax, static/integrity QA, package integrity/build and full-runtime PHPCS/WPCS (47 runtime PHP files; zero errors, zero warnings and zero fixable findings).
- This is not evidence that authenticated WordPress/MariaDB runtime, deep ledger/inventory/payroll reconciliation, role-based SOP workflows, live browser/host compatibility or backup/rollback were executed. Current manifest fields explicitly show authenticated live browser E2E and live database integration as not executed; do not treat source CI as those tests.

# Stockland ERP audit-status adjudication — 2026-10-12

## Purpose

This record separates GitHub source-gate evidence from authenticated target-host acceptance. The source-gate status is determined by the linked workflow run; repository-authored release metadata must not override or mislabel that result. PR #19 is merged and PR #20 is closed unmerged.

## Independently verified source status

- GitHub repository default branch: `main`.
- Main release identity at the time of this adjudication: plugin and `RELEASE_MANIFEST.json` identify **16.1.57**.
- PR [#19](https://github.com/stocklandethiopia/stockland-erp-project/pull/19) is **merged** at commit `f85776e0f4dd92f49d1af3e0637beff4b861fe17` (2026-10-12 19:41 UTC).
- PR [#20](https://github.com/stocklandethiopia/stockland-erp-project/pull/20) is **closed unmerged as a duplicate**. Its correction and regression test were transferred into PR #19; this is not a second merge.
- GitHub Actions [run 38298449906](https://github.com/stocklandethiopia/stockland-erp-project/actions/runs/38298449906) passed on PR #19's tested head `9ff36cea140f513859bd0a9e9584021b26329921`. The verified report was full-runtime PHPCS/WPCS with 0 errors, 0 warnings and 0 fixable findings across 47 runtime PHP files, and passing PHP lint, PHPUnit, static/integrity QA, JavaScript syntax, package build and the requisition-join regression.
- GitHub search returned no open pull requests at the time of this review.

## How to read source-gate and target-host evidence

The old binary certification fields were manually authored repository metadata, not GitHub Actions results. They have been removed from the current release manifest so the manifest does not contradict the linked source-gate evidence.

Source CI can pass independently of target-host acceptance. The current source-gate result is linked to its exact commit and installer artifact; authenticated database, browser, workflow, restore and rollback evidence must be recorded as their own acceptance results.

## Live / exact-release evidence still pending

- A user-provided System Health capture from installed release 16.1.61 reported `requisition_items_without_header: UNKNOWN`. Source review found a bad join to a nonexistent requisition `id`; the merged correction uses `requisition_id` and the regression test passed in the source gate. The corrected package has not yet been installed and deep-scanned on the target host, so the actual orphan count is still unverified.
- Quick health intentionally deferred deeper audit-chain, ledger-reference, negative-stock, balance reconciliation, reservation, AP/AR, GL, procurement/PLEDGER, identifier, payroll-classification and import/workflow checks. Deferred or UNKNOWN results are not PASS and are not equivalent to zero.
- Database foreign-key/relationship coverage and actual orphan counts need read-only review on a disposable staging clone before any migration is considered.
- The separate local `STOCKLAND_ERP_16.1.62_FRESH_INSTALL_ONE_PACK_CANDIDATE.zip` was previously recorded with SHA-256 `38b7b2c2fdb3910cf712c5b329a8d75a07ae977063de4897abf2b8fbfb122459`. It is not yet synchronized as a complete exact-16.1.62 GitHub source release and has not passed CI on that exact release tree. The 16.1.57 source run must not be cited as 16.1.62 CI.
- Authenticated role/access and procurement workflows, SKU search/save, manual branch Daily POS XLSX idempotency, catalog-only Product Master import, ledger/finance/payroll reconciliation, target-host/browser compatibility, responsive authenticated browser testing, backup restoration and rollback remain pending.

## Current gate decision

| Gate | Current status | Evidence boundary |
|---|---|---|
| Merged source baseline / full-runtime PHPCS/WPCS | **PASS** | Run 38298449906 on tested PR #19 head; 16.1.57 baseline plus fixes |
| PR #19 status | **MERGED** | Merge commit `f85776e0f4dd92f49d1af3e0637beff4b861fe17` |
| PR #20 status | **CLOSED UNMERGED / DUPLICATE** | Correction carried by PR #19 |
| Exact 16.1.62 repository source + CI | **PENDING** | No exact-release synchronization and CI evidence yet |
| Authenticated deep integrity and ledger reconciliation | **PENDING** | Corrected live rescan not evidenced |
| Authenticated workflow/browser/host acceptance | **PENDING** | Not run/evidenced on target staging host |
| Backup restore and rollback | **PENDING** | No test evidence in current records |
| Target-host acceptance | **PENDING EVIDENCE** | Missing mandatory acceptance evidence listed above; this is separate from the source-gate result |

## Release decision

Keep [CAP issue #3](https://github.com/stocklandethiopia/stockland-erp-project/issues/3) open until the mandatory target-host acceptance records are attached to the exact source commit and installer SHA-256. The source-gate result alone does not document those target-host checks.

This status record corrects wording and establishes traceability; it does not claim the pending checks have been executed.
