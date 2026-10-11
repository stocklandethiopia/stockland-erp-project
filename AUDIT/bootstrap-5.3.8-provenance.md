# Bootstrap CSS dependency provenance — candidate 16.1.57

- Required local dependency: Bootstrap CSS **5.3.8**.
- Upstream repository: https://github.com/twbs/bootstrap
- Upstream tag/path: `v5.3.8:dist/css/bootstrap.min.css`
- Upstream Git blob SHA-1: `1d8bac41b3b586410aed1077d9abc05e169cb924`
- Official documentation SRI: `sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB`
- Official release notes: https://blog.getbootstrap.com/2025/08/25/bootstrap-5-3-8/
- Official CDN quick start: https://getbootstrap.com/docs/5.3/getting-started/introduction/

The upstream CSS was fetched from the official tagged repository and saved in this branch under `audit/vendor/bootstrap-5.3.8/bootstrap.min.css` as a provenance/reference asset. The canonical ERP plugin path `stockland-erp-app/assets/bootstrap.min.css` must be updated from this exact asset when the full source tree is delivered. CI checks the exact upstream Git blob hash rather than trusting the version comment alone.

The repository still does not contain the complete plugin package in this change, so **Bootstrap dependency integration into the installable ZIP remains pending**. Do not declare the packaged ERP dependency fixed until the canonical local asset is replaced, the package is rebuilt, the CSS/module tests pass, and a new ZIP checksum is issued.
