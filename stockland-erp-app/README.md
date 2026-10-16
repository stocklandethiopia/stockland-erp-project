# Stockland Ethiopia ERP 16.1.67

**Stockland ERP 16.1.67 is a cumulative production update for the existing Stockland Ethiopia ERP. It continues the current system and preserves the established business-data contract, accounting history, role boundaries and operational workflows.**

## Included capabilities

- Command Center and role-aware navigation with a responsive desktop, tablet and mobile interface.
- Product Master and live SKU lookup for stockable products and variants.
- Retail POS and branch-scoped Daily POS Import from external POS Excel workbooks.
- Inventory, requisitions, transfers, Central Warehouse receiving, returns, stock count and reconciliation.
- Finance & Procurement, supplier and customer master data, purchase orders, supplier invoice/AP, customer AR, payment allocation, expense workflows, general ledger and financial reporting.
- HR and payroll, user/organization management, audit log and system-health tools.

## Finance & Procurement workflows

**Purchase orders:** Management selects an existing active supplier and existing stockable Product Master SKUs, then enters quantity and unit cost for each line. The purchase order can be created and submitted without first creating an AP invoice or AR transaction. The Owner makes the independent approval decision. The Central Warehouse Manager records the actual accepted quantity against the approved order.

**Supplier invoice and accounts payable:** Management prepares an invoice against the Owner-approved purchase order and recorded receiving. The Owner's separate invoice decision posts the matched accounting entries. Payments are recorded and allocated as a separate step against posted invoices.

**Branch expenses:** The active Branch Manager enters and makes the final decision on expenses for the assigned branch. The branch is derived from the active ERP profile; the form does not let the user claim another branch.

**Central Warehouse expenses:** The active Central Warehouse Manager enters and makes the final decision on expenses for Central Warehouse.

**Management and Owner expenses:** Each role records its own corporate expense without choosing a branch. Management's corporate expense is submitted for the Owner's independent decision; the Owner's own corporate expense posts to the Owner corporate record. Both roles can review location-expense reports but do not decide branch or warehouse expenses.

## Operating authority

| Work | Operational entry | Decision / final authority |
|---|---|---|
| Daily external POS workbook | Authorized branch salesperson or Branch Manager | Branch Manager releases the staged import to ERP |
| Branch expense | Assigned Branch Manager | Assigned Branch Manager |
| Central Warehouse expense | Central Warehouse Manager | Central Warehouse Manager |
| External product purchase | Management | Independent Owner approval |
| Purchase receiving | Central Warehouse Manager | Actual accepted quantity posts to inventory |
| Supplier invoice/AP and finance payment | Management prepares the transaction | Owner makes the configured finance decision |
| Corporate expense | Owner or Management, each for their own expense | Owner's own expense posts to the Owner corporate record; Management submits to Owner approval |

The ERP enforces the authority model on the server as well as in the interface. A hidden button is not treated as an access-control mechanism.

## Warehouse movement and branch receiving

After a requisition is approved, the Central Warehouse Manager may choose **PICK / READY** immediately. A reservation or pickup date is not required. The Manager may instead choose **RESERVE** to schedule collection; a pickup date is required for that appointment. Warehouse Employees may PICK only after the Manager has reserved the requisition. The ERP checks available central stock and posts the actual approved dispatch quantity through the stock ledger. A repeated PICK cannot post the same movement twice. The Branch Manager records the actual quantity received; a variance requires a reason and only the quantity actually received is added to branch stock. Physical count remains a separate inventory-count workflow.

## Install or update in WordPress

### Update an existing Stockland ERP installation

1. Back up the current plugin files and WordPress database.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and select this Stockland ERP installer ZIP.
3. Use WordPress's replace/update option for the existing Stockland ERP plugin. Do not uninstall the existing plugin or remove its data.
4. Open the ERP front end and confirm System Health. The existing ERP records and configured roles/locations are preserved.

### Clean WordPress installation

1. Upload this ZIP through **Plugins → Add New Plugin → Upload Plugin**, then install and activate it.
2. Complete the protected ERP setup from the WordPress administrator account.
3. Use the Owner's **Users & Organization** controls to assign each active ERP role and branch/location.
4. Confirm System Health and the required Core, Accounting/Procurement and HR/Payroll setup indicators before day-to-day operation.

Use **Finance & Procurement** from ERP navigation for purchase orders, expenses, AP/AR, payments and financial reporting.

The update preserves the existing business-data model: 49 application tables across Core/Operations, Accounting/Procurement and HR/Payroll, with the existing core, accounting and HR schema-version markers.

## Product entry and purchase workflow

Product Master is the SKU catalog. Adding a product master record does not itself post stock. For an external supplier purchase, select a stockable SKU from the live picker, enter quantity and unit cost, and create/submit the purchase order. Receiving the Owner-approved order is the stock-posting step. Branch replenishment continues through the requisition and transfer workflows.

## Design and compatibility

The interface uses the local Bootstrap 5.3.8 stylesheet and a compact six-module responsive CSS build. Finance forms use live data, role-scoped actions and server-side validation. The plugin does not seed demonstration sales, stock movements, payroll entries, orders or customer transactions.


## Release compatibility

This cumulative release is for the existing Stockland ERP 16.1.66 data model and preserves the 49-table business contract: 29 Core/Operations, 16 Accounting/Procurement and 4 HR/Payroll tables. It retains the established core, accounting and HR schema-version markers and adds no business table. The frontend bundles Bootstrap 5.3.8 locally and does not require a CDN at runtime. The installer contains production application files only; it does not include development tooling or QA scripts.