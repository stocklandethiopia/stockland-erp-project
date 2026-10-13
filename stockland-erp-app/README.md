# Stockland Ethiopia ERP 16.1.65

A unified operations platform for Stockland Ethiopia, built for branch retail, Central Warehouse, procurement, finance, HR and management control. The interface uses a local Bootstrap 5.3.8 foundation and Stockland's responsive component system.

## Daily operations

- **Command Center:** operational summaries, alerts, role-based shortcuts and SOP guidance.
- **Product Master:** maintain the central product catalogue and select existing SKUs throughout sales, procurement and inventory workflows.
- **Retail POS:** create ERP sales using active Product Master SKUs and post the resulting stock, finance and audit records.
- **Daily POS Import:** upload the branch's end-of-day Excel workbook from the external POS, validate and stage the rows, then have the Branch Manager review, edit and release the confirmed batch into ERP ledgers.
- **Inventory & Central Warehouse:** track stock availability, reservations, receiving, transfers and requisitions using live ledger data.
- **Reports & Analytics:** review daily inventory, sales, stock movement, finance and branch performance within the active user's scope.

## Finance & Procurement

- **Product purchase:** Management selects an active supplier and existing Product Master SKU, enters quantity and unit cost, and submits the purchase order. The active ERP Owner makes the final purchase-order decision. Central Warehouse receives accepted quantities against the approved order.
- **Supplier invoices / Accounts Payable:** match supplier invoices to the approved purchase and accepted receipt before recording the payable and payment allocation.
- **Customer invoices / Accounts Receivable:** maintain customer invoices and apply payments to the relevant open balances.
- **Branch expense:** the Branch Manager records and makes the final decision only for the assigned branch. Approval posts one balanced journal; rejection records a reason and leaves the ledger unchanged.
- **Central Warehouse expense:** the Central Warehouse Manager records and makes the final decision only for Central Warehouse.
- **Corporate expense:** Owner and Management record their own expenses without selecting a branch. Corporate expenses post to the ledger and remain visible in the expense report.
- **Expense reporting:** Owner and Management can review location expenses across the business; location-expense decisions remain with the assigned Branch or Central Warehouse Manager.

AP and AR are separate accounting stages. Creating a purchase order from an existing Product Master SKU does not require a supplier invoice, customer invoice or payment record to exist first.

## Role-based workspaces

| Role | Main responsibilities |
| --- | --- |
| Owner | Organization control, purchase-order decisions, finance review, statutory payroll decisions, company-wide reporting and access management |
| Management | Product Master, purchase requisitions and purchase orders, supplier/customer records, AP/AR preparation, corporate expenses, reporting and management-stage approvals |
| Branch Manager | Assigned-branch operations, Daily POS Import review/release, branch expenses and final branch-expense decisions, requisitions and daily closing |
| Central Warehouse Manager | Central Warehouse operations, approved purchase receiving, stock reservation/transfer workflow and final Central Warehouse expense decisions |
| Warehouse Employee | Execute assigned picking and stock-handling work |
| Sales Employee | Retail sales, Product Master SKU selection and assigned Daily POS Import staging |
| Auditor | Review audit records and authorized operational evidence |

ERP role and location scope are checked by the server for each protected action. WordPress Administrator status alone does not replace an active Stockland ERP profile.

## Install or update in WordPress

1. Open **WordPress Admin → Plugins → Add New Plugin → Upload Plugin**.
2. Select the Stockland ERP ZIP package and choose **Install Now**.
3. For an existing Stockland ERP installation, use the in-place plugin update flow and preserve the site's database and uploaded business documents.
4. Activate the plugin, then open **Stockland ERP** from the WordPress menu.
5. Sign in with the assigned Stockland ERP profile and follow the role-based SOP guide in the application.

## Operating principles

- Existing Product Master SKUs are reused across purchase, stock and sales workflows.
- Product Master maintenance does not itself create stock movements.
- External POS data enters ERP only through the validated Daily POS Import release workflow.
- Stock quantities change through posted sales, approved receiving and authorized inventory operations.
- Finance transactions are recorded through the accounting workflow and balanced journal posting.
- Approval decisions, role assignments and consequential business transitions are written to the ERP audit trail.
- AI guidance, when enabled in a future approved workflow, is advisory and does not independently change business records.

## Technical foundation

- WordPress plugin architecture with local CSS/JavaScript assets.
- Bootstrap 5.3.8 and responsive layouts for desktop, tablet and mobile.
- Existing ERP schema contract: 49 business tables across Core/Operations, Finance/Procurement and HR/Payroll.
- Existing schema markers: Core 16.1.1, Accounting 16.1.15 and HR/Payroll 16.1.3.
- ERP-managed role authority, location scope, transaction posting and audit history.
