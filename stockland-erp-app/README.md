# Stockland Ethiopia ERP 16.1.77

**Stockland ERP** is a role-based, responsive operations and finance platform for Stockland Ethiopia, supporting the central warehouse, branches, Product Master, retail operations, procurement, finance, reporting and payroll.

## Cumulative workflow corrections in 16.1.77

- Requisition creation requires a stable retry key; identical retries return the existing request and a reused key with different content returns a conflict.
- Branch requisition drafts display live branch availability and read-only Central Warehouse/peer-branch sourcing. A failed source lookup is not presented as zero stock and does not mutate inventory.
- Authorized Central Warehouse Managers and Warehouse Employees may pick an approved requisition directly without mandatory pickup scheduling. Stock availability, reservation, transfer status, workflow and audit are checked in the transaction before ledger dispatch.
- The PAYE preview and server calculation deduct the employee pension before PAYE under the versioned Stockland rule; the ETB 30,000 regression expects pension 2,100, PAYE 7,715 and net 20,185. Historical posted payroll is not silently recalculated.
- Owner-created corporate expenses remain pending and unposted rather than self-approving or creating an unreviewed GL journal.

## Included capabilities

- Data-backed role-scoped dashboard with posted-sales trend, category mix, branch performance, recent transactions and explicit empty/error states; responsive desktop, tablet and mobile shell with persistent operational sidebar.
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
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin** and select the Stockland ERP 16.1.77 installer ZIP.
3. For an in-place update, choose the option to replace the existing Stockland ERP plugin when WordPress offers it. For a fresh installation, install and activate the plugin.
4. Open the Stockland ERP front end. Use the Owner's Users & Organization controls to assign each active ERP role and its branch/location.
5. Use **Finance & Procurement** from the ERP navigation for purchasing, expenses, AP/AR, payments and financial reporting.

The update preserves the existing business-data model: 49 application tables across Core/Operations, Accounting/Procurement and HR/Payroll, with the existing core, accounting and HR schema-version markers.

## Product entry and purchase workflow

Product Master is the SKU catalog. Adding a product master record does not itself post stock. For an external supplier purchase, select a stockable SKU from the live picker, enter quantity and unit cost, and create/submit the purchase order. Receiving the Owner-approved order is the stock-posting step. Branch replenishment continues through the requisition and transfer workflows.

## Design and compatibility

The interface uses the local Bootstrap 5.3.8 stylesheet and a compact six-module responsive CSS build. Finance forms use live data, role-scoped actions and server-side validation. The plugin does not seed demonstration sales, stock movements, payroll entries, orders or customer transactions.


## Acceptance boundary

The automated source/package gate validates code quality and package integrity; it does not by itself establish production acceptance. Target-host acceptance is currently **PENDING** until the exact packaged SHA-256 is verified on the intended WordPress/PHP/MariaDB environment, the 49-table schema and existing data are reconciled, role-separated requisition/picking, procurement, finance, imports and payroll flows pass, and backup restoration plus rollback are demonstrated. The plugin does not include demonstration sales, stock movements, payroll entries or orders.


**Release acceptance:** source/package CI is separate from authenticated target-host acceptance. Do not infer live database reconciliation, backup restoration, rollback or payroll/legal approval from CI alone. The package remains blocked from production certification until the master release acceptance record contains evidence for those gates.
