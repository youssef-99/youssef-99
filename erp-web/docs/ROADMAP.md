# ERP Web — build status

Rebuild of the pharmacy ERP described in `ERP_Web_Features.docx` (not committed; attach it again in a new session)
with Laravel 13 + Filament 5. Read `docs/CONVENTIONS.md` first.

## Done (foundation)

- Schema for every module (`database/migrations/2026_01_01_0001..0006_*`): general, accounting, parties, inventory, sales, purchases.
- Eloquent models for all 61 tables, with relations and casts.
- Services: numbering, settings and default accounts, journal engine, stock ledger with moving-average cost,
  sales / purchase / stock-document / voucher / manual-journal posting, customer and supplier sub-ledger accounts
  and opening balances, ZATCA (ICV/PIH chain, UBL XML, SHA-256 hash, QR TLV, submit client).
- Filament panel: Arabic RTL default, English switch, Cairo font, username-or-email login, single session per user,
  My Profile / change password, module sidebar groups, group-based permissions (view/add/edit/delete/post per page).
- Shared Filament building blocks: `ErpResource`, `IsDocumentResource`, document create/edit/view pages,
  `Fields`, `Columns`, `Filters`, `DocumentLines` (line grid, live totals, barcode scanner), `DocumentActions`.
- Reference pages: General > Branches (simple master), Sales > Sales Invoices (document).
- Seeders: `SystemSeeder` (company, branch, warehouse, fiscal years, geography, currencies, units, 55-account chart,
  default posting accounts, admin user `admin` / `password`), `DemoSeeder` (items, customer, supplier, posted documents).
- Tests: `PanelSmokeTest` renders every page; `SalesInvoiceFormTest` creates and posts an invoice through the form.
- Laravel Boost installed (guidelines in `AGENTS.md`, skills in `.claude/skills`, MCP in `.mcp.json`).

## Next (one module per work stream)

1. **General**: companies, warehouses, user groups with permissions tree (`PermissionRegistry::tree()`), users
   (add/edit, terminate sessions), countries / cities / districts / regions, currencies, activities, stakeholders,
   EGS units, fiscal years, System Settings page (default accounts `Settings::ACCOUNT_KEYS`).
2. **Accounting**: chart of accounts (tree, print, Excel export), banks, cost centers, journal vouchers (lines grid,
   Excel import), receipt / cash payment / cheque payment vouchers, credit / debit notices, cheque receipt vouchers,
   cheque collection, murabaha financing contracts with installments, letters of credit and fees.
3. **Inventory**: units, item categories, groups, agents, manufacturers, active ingredients, items (bulk add from
   file), batch expiry dates, serial numbers, balance limits, annual / periodic stocktakes + approval, positive /
   negative adjustments, outbound / inbound transfers, invoice items cost, item actions (update purchase price, send to e-POS).
4. **Sales**: sales policies, customers (full form, attachments, transfer between salesmen), salesmen, marketing reps,
   customer groups, quotations, sales returns, customer orders (approve / reject / partial), POS invoices and returns
   (payment: cash, Mada/Visa, change due), doctors, drug doses, promotions, staff incentives, loyalty points customers,
   ZATCA sales invoices / returns / credit notes pages.
5. **Purchases + Cash Transactions**: supplier groups, suppliers, purchase orders, purchase invoices (print goods
   receipt note, Rasd), purchase returns, foreign purchase invoices; cash suppliers / customers, cash purchase and
   sales invoices and returns, cash ZATCA pages.
6. **Reports, dashboard, printing**: report framework (`App\Reports\ReportRegistry::permissionPages()` hooks into
   permissions), reports hub with the 13 groups, report viewer (print, XLSX/CSV export via openspout), dashboard
   (top 10 items by quantity, sales by branch, period and with/without tax filters), `PrintController` templates
   for every document type with the ZATCA QR.
