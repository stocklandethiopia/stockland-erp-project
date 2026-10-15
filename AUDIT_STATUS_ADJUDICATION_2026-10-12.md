# Current GitHub status — 2026-10-13 (supersedes the older snapshots below)

- Current release line: **Stockland ERP 16.1.65**. PRs #25–#32 are merged. PR #31 aligns the checked-in manifest template with generated per-run evidence; PR #32 updates release records to the resulting canonical checksum.
- Tested runtime-source/build commit: `690cb6dbc2ea93a1da640f287344977a665302ae`. The subsequent post-PR #32 documentation snapshot is `cc551e01d400f7b3c30e836b93516499fe38215a`; [compare](https://github.com/stocklandethiopia/stockland-erp-project/compare/690cb6dbc2ea93a1da640f287344977a665302ae...cc551e01d400f7b3c30e836b93516499fe38215a) confirms that it changes only `AUDIT/` records, not packaged runtime files.
- Exact post-merge source/package gate: [run #38423682999 — SUCCESS](https://github.com/stocklandethiopia/stockland-erp-project/actions/runs/38423682999). Post-merge CodeQL: [run #38423682995 — SUCCESS](https://github.com/stocklandethiopia/stockland-erp-project/actions/runs/38423682995).
- Current candidate installer: `STOCKLAND_ERP_16.1.65_FINAL_CANONICAL_BOOTSTRAP_5.3.8.zip`; ZIP SHA-256 `95febac4bb9ffe8d4a1bd7b1fc0c91002dfa3f75122beb7a779bd7196a59eda4`; [Actions artifact #11828421033](https://github.com/stocklandethiopia/stockland-erp-project/actions/runs/38423682999/artifacts/11828421033). The outer artifact digest is `a2f4dc5ea2826f7fdab9b9799297a6984de50a978b9dd426ff837437dbfc92e8` and must not be substituted for the inner ZIP checksum.
- Source/package checks passed: PHP lint, Composer/test suite, production-service coding standard, full-runtime PHPCS/WPCS on 47 runtime PHP files with zero errors, zero warnings and zero fixable findings, JavaScript syntax, CSS/Bootstrap build verification, ERP static/integrity/workflow checks, 49-table/DDL contract assertions, ZIP integrity.
- Release history is now consolidated in [AUDIT/16.1.58-16.1.65-release-lineage-and-certification.md](AUDIT/16.1.58-16.1.65-release-lineage-and-certification.md). 16.1.64 has its own historical source/package PASS (run #38327425623, SHA-256 `14fcfe7ae64a40dc192e8c02ba487c753a00b7bc6099ac39c915027a1a4e001b`) but is superseded. Earlier 16.1.58–16.1.63 entries are retained as historical evidence, not as current package authority.
- No formal GitHub Release entry exists at this check; #11828421033 is a retention-limited Actions artifact, not a published Release.
- **Release decision: SOURCE/PACKAGE PASS; production certification remains pending.** This workflow did not access the live WordPress site or business database. Authenticated live browser/DB, deep integrity and ledger reconciliation, owner-management-warehouse SOP E2E, SKU/product saving, POS/catalog imports, payroll, target-host compatibility, and backup restore/rollback remain to be executed and recorded against the exact installer SHA. No historic test or checksum is substituted for the current one.

## Follow-up correction — 2026-10-15 legacy binary release flags

The initial 16.1.57 source-import PR (#1) introduced manually authored `live_certification: false` and `production_certified: false` fields in the release manifest, alongside human-readable staging/acceptance warnings in the bundled README. GitHub Actions did not generate those fields. The later cleanup removed `live_certification` keys but matched only that name, leaving six `production_certified` keys in the nested historical 16.1.53, 16.1.56 and 16.1.57 manifest records. The prior statement that all binary certification fields had been removed was therefore incomplete.

This follow-up removes both legacy key names from the current manifest and adds the same protection to package generation. The README and quick-health copy now use explicit `PENDING` / `UNVERIFIED` wording. The PHP quick-health response deliberately retains its boolean `live_ready = false` compatibility value when deep checks were not run: that is fail-closed runtime logic, not a release certification flag. No readiness guard has been weakened. The cleanup is not a new source-gate PASS until the branch workflow completes; target-host acceptance is still a separate evidence requirement.

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
