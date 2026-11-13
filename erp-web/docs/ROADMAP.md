# ERP Web — build status

Rebuild of the pharmacy ERP described in the client's features document (not committed; attach it again in a new
session) with Laravel 13 + Filament 5. Read `docs/CONVENTIONS.md` first.

## Done

### Foundation
- Schema for every module (`database/migrations/2026_01_01_*`) plus additive per-module migrations (`2026_02_01_*`).
- Eloquent models for all tables; services for numbering, settings, journal, stock ledger (moving average), posting.
- Filament panel: Arabic RTL default, English switch, username-or-email login, single session per user,
  My Profile / change password, group-based permissions (view/add/edit/delete/post/approve per page).
- Shared building blocks: `ErpResource`, `IsDocumentResource`, document create/edit/view pages, `Fields`, `Columns`,
  `Filters`, `DocumentLines`, `DocumentActions`, `HasErpPermission` (custom pages, `$pagePermissions`).

### Modules (82 resources, every page in the spec; page paths follow the spec, e.g. `/sales/customers/add`)
- **General**: companies, branches, warehouses (`/general/stores`), user groups with the permission tree,
  users (password reset, terminate sessions), countries / cities / districts / regions, currencies, activities,
  stakeholders, ZATCA EGS units (encrypted secrets), fiscal years, System Settings (default posting accounts,
  VAT, ZATCA, loyalty point value).
- **Accounting**: chart of accounts (print, Excel export, delete protection), banks, cost centers, journal vouchers
  (Excel import, balanced posting), receipt / cash payment / cheque payment vouchers, credit / debit notices,
  cheque receipt vouchers, cheque collection, murabaha financing contracts with installment schedules.
- **Inventory**: units, categories, groups, agents, active ingredients, manufacturers, items (bulk import from
  XLSX/CSV, ZATCA exemption reasons), batch expiry dates, serial numbers, balance limits, positive / negative
  adjustments, outbound / inbound transfers, annual / periodic stocktakes + approval, invoice items cost,
  item actions (prices, activation, e-POS export).
- **Sales**: sales policies, customers (full form, attachments, export, transfer between salesmen), salesmen,
  marketing reps, customer groups, quotations (convert to invoice), sales invoices, sales returns (quantity limits),
  customer orders (approve / partial / reject / convert).
- **POS** (`app/Filament/Resources/Pos`, Sales menu): POS invoices and returns (cash, Mada/Visa, change due,
  80mm receipt), doctors, drug doses, promotions, staff incentives, loyalty points customers.
- **ZATCA**: UBL 2.1 XML with XAdES block, ICV/PIH chain, invoice hash, ECDSA signature, QR tags 1-9,
  credit / debit notes (customer discount, invoice correction), reporting / clearance client with offline mode,
  `zatca:submit-pending` scheduled every 10 minutes, ZATCA pages for sales and cash documents.
- **Purchases**: supplier groups, suppliers, purchase orders (convert to invoice), purchase invoices (goods receipt
  note, Rasd file — sandbox only), purchase returns, letters of credit, L/C fees, foreign purchase invoices.
- **Cash Transactions**: item categories, items, cash suppliers / customers, cash purchase and sales invoices and returns.
- **Printing** (`/print/{type}/{id}`): sales (A4 + `?layout=80mm`), purchase, purchase-grn, stock, voucher, journal,
  cheque-collection, chart-of-accounts, financing-contract; company letterhead, amount in words, ZATCA QR (qrcodejs).
- **Dashboard**: stats, top 10 items by quantity, sales by branch; period (default last 7 days) and with/without tax.
- **Reports**: hub with 13 groups, filter page per report at `/admin/reports/<group>/<report>`, viewer in a new tab
  (company header, print, XLSX/CSV export, search, zoom). All 52 catalog reports are implemented
  (`app/Reports/<Group>/*Report.php`; see the "HOW TO ADD A REPORT" PHPDoc in `app/Reports/Report.php`).

### Tests
`php artisan test --compact` runs the whole suite (feature tests per module under `tests/Feature/<Module>`,
`PanelSmokeTest` renders every page, `PermissionGuardsTest`).

## Next
- ZATCA onboarding: CSR generation and compliance / production CSID API calls (fields exist on EGS units).
- Rasd (SFDA) and e-POS live integrations (files are generated; no API calls yet).
- Report viewer: PDF / DOCX export formats.
- QR code and barcode libraries load from cdnjs; bundle them locally for offline installs.
- Sidebar sub-groups (module > sub-group > page) like the original 3-level menu.
- Queue the ZATCA submission instead of sending it inline after commit.
