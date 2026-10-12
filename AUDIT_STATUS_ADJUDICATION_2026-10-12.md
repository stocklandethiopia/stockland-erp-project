# Stockland ERP audit-status adjudication — 2026-10-12

## Purpose

This record ratifies the distinction between repository-authored release-control metadata, GitHub source-gate results, and authenticated production acceptance. It supersedes prior wording that incorrectly described `live_certification: false` as if GitHub Actions had generated a source-audit failure, and any current-tense statement that PR #19 or PR #20 remains open.

## Independently verified source status

- GitHub repository default branch: `main`.
- Main release identity at the time of this adjudication: plugin and `RELEASE_MANIFEST.json` identify **16.1.57**.
- PR [#19](https://github.com/stocklandethiopia/stockland-erp-project/pull/19) is **merged** at commit `f85776e0f4dd92f49d1af3e0637beff4b861fe17` (2026-10-12 19:41 UTC).
- PR [#20](https://github.com/stocklandethiopia/stockland-erp-project/pull/20) is **closed unmerged as a duplicate**. Its correction and regression test were transferred into PR #19; this is not a second merge.
- GitHub Actions [run 38298449906](https://github.com/stocklandethiopia/stockland-erp-project/actions/runs/38298449906) passed on PR #19's tested head `9ff36cea140f513859bd0a9e9584021b26329921`. The verified report was full-runtime PHPCS/WPCS with 0 errors, 0 warnings and 0 fixable findings across 47 runtime PHP files, and passing PHP lint, PHPUnit, static/integrity QA, JavaScript syntax, package build and the requisition-join regression.
- GitHub search returned no open pull requests at the time of this review.

## What `live_certification: false` means

The top-level `live_certification` and `quality.live_certification` values in `RELEASE_MANIFEST.json` are **manually authored repository data**. GitHub Actions does not generate or automatically set them. A value of `false` is therefore **not a GitHub source-gate failure** and must not be presented as one.

The value remains false as a separate release-control decision because production certification has not been established. Source CI can pass while authenticated target-host and data-integrity acceptance remain incomplete. Do not change this field to true just because the source workflow is green.

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
| Overall live certification | **NOT CERTIFIED / HOLD** | Missing mandatory evidence above; not a GitHub-generated failure |

## Release decision

Keep both manifest `live_certification` values false until all mandatory release gates pass with timestamped evidence tied to one exact source commit and installer SHA-256. Keep [CAP issue #3](https://github.com/stocklandethiopia/stockland-erp-project/issues/3) open. No production deployment or production transaction write is authorized by this source-gate result.

This status record corrects wording and establishes traceability; it does not claim the pending checks have been executed.
