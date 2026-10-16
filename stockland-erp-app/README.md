# Stockland Ethiopia ERP 16.1.67

**Stockland ERP** is a role-based, responsive operations and finance platform for Stockland Ethiopia, supporting the central warehouse, branches, Product Master, retail operations, procurement, finance, reporting and payroll.

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

## Install or update in WordPress

1. Back up the current plugin files and WordPress database before an in-place update.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and select the Stockland ERP 16.1.67 installer ZIP.
3. For an in-place update, choose the option to replace the existing Stockland ERP plugin when WordPress offers it. For a fresh installation, install and activate the plugin.
4. Open the Stockland ERP front end. Use the Owner's Users & Organization controls to assign each active ERP role and its branch/location.
5. Use **Finance & Procurement** from the ERP navigation for purchasing, expenses, AP/AR, payments and financial reporting.

The update preserves the existing business-data model: 49 application tables across Core/Operations, Accounting/Procurement and HR/Payroll, with the existing core, accounting and HR schema-version markers.

## Product entry and purchase workflow

Product Master is the SKU catalog. Adding a product master record does not itself post stock. For an external supplier purchase, select a stockable SKU from the live picker, enter quantity and unit cost, and create/submit the purchase order. Receiving the Owner-approved order is the stock-posting step. Branch replenishment continues through the requisition and transfer workflows.

**Warehouse requisitions:** After the required branch, Management and Owner decisions are complete, the Central Warehouse Manager can choose **PICK / READY** immediately from an Approved requisition when sufficient stock is available, or choose **RESERVE** first to set a pickup appointment. Reservation is optional for the Manager; a pickup date is required only when reserving. Warehouse Employees pick Reserved requisitions. Picking posts through the existing transfer ledger and records workflow/audit evidence; a repeated PICK does not create another stock movement. Branch receipt is a separate step performed by the authorized Branch Manager. Physical count remains a separate inventory-adjustment workflow, not a movement action.

## Design and compatibility

The interface uses the local Bootstrap 5.3.8 stylesheet and a compact six-module responsive CSS build. Finance forms use live data, role-scoped actions and server-side validation. The plugin does not seed demonstration sales, stock movements, payroll entries, orders or customer transactions.


## Production release status

The established Stockland WordPress hosting baseline for version 16.1.66 was previously reported **LIVE READY** on WordPress 7.1.3, PHP 8.4.25 and MariaDB 11.4.13, with all 49 application tables, 96 REST v16 routes and the full deep-integrity report passing. This accepted baseline is carried forward; a separate generic staging-clone cycle or repeat of the entire host qualification is not required.

Version 16.1.67 is a cumulative production installer for that existing site. Back up the plugin files and database, then apply the established in-place WordPress update. After updating, verify the release-specific dashboard analytics and payroll-calculation changes together with the agreed System Health result. Source/package CI does not deploy to WordPress, so the new release's post-update observations must be recorded only after the exact package is installed on the host.
