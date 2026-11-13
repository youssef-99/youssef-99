# ERP Web — developer conventions

Stack: **Laravel 13**, **Filament 5.11** (Livewire 4), PHP 8.3, SQLite in dev / MySQL or PostgreSQL in production.
UI is Arabic RTL by default with an English switch (user menu and login page).

## Layout

```
app/
  Enums/                 Module, Permission, SalesDocumentType, PurchaseDocumentType, StockDocumentType, VoucherType
  Exceptions/ErpException.php   business-rule errors shown to the user
  Models/                one model per table; Concerns/HasBilingualName (name, label), Concerns/ComputesLineAmounts
  Services/              ALL business logic (posting, stock, numbering, ZATCA)
    Calc/DocumentCalculator.php   line + totals arithmetic (pure functions)
    JournalService        record()/removeFor()/balance(); every posted document produces one journal entry
    StockService          stock ledger: move()/balance()/batchBalances()/resolveBatch(); moving average cost
    SalesDocumentService  initialize/recalculate/post/unpost for quotations, orders, invoices, returns, POS, cash sales
    PurchaseDocumentService   same for purchase orders, invoices, returns, foreign and cash purchases
    StockDocumentService  adjustments, transfers, stocktakes
    VoucherService        receipt/payment vouchers, notices, cheque collection
    ManualJournalService  manual journal vouchers
    PartyAccountService   customer/supplier sub-ledger accounts + opening balance entries
    DocumentNumber        sequential numbers per (key, branch, year); nextCode() for master codes
    Settings              key/value settings; default posting accounts (Settings::ACCOUNT_KEYS)
    Zatca/                ZatcaService (ICV/PIH chain, hash, QR TLV, submit), UblInvoiceBuilder
  Filament/
    Resources/ErpResource.php     base class for every resource (module, permission key, translated labels)
    Resources/Pages/              CreateDocumentRecord, EditDocumentRecord, ViewDocumentRecord
    Resources/<Module>/           resources grouped by module: General, Sales, Purchases, Cash, Accounting, Inventory
    Concerns/                     IsDocumentResource, HasPostedTabs, HasErpPermission (custom pages)
    Support/                      Fields, Columns, Filters, DocumentLines (line grids + totals + barcode scanner)
    Actions/DocumentActions.php   post / unpost / print actions
  Support/PermissionRegistry.php  builds the permission tree from resources/pages (+ ReportRegistry)
lang/modules/<module>/ar.json     Arabic translations, one file per module (auto-registered)
```

## Rules

1. **Labels are written in English** (`->label('Customer Code')`). Every field, column, filter, entry and
   action is auto-translated (`translateLabel()` is configured globally in `AppServiceProvider`).
   Section headings, notifications, tab names and other free strings must use `__('...')`.
   Add the Arabic for every new string to `lang/modules/<module>/ar.json` (use the Arabic labels in the spec).
2. **Resources extend `App\Filament\Resources\ErpResource`** and set `$module`, `$permissionKey`
   (`<module>.<slug>`, e.g. `sales.customers`), `$modelLabel`, `$pluralModelLabel`, `$slug` (`sales/customers`,
   matching the spec URL), `$navigationIcon`, `$navigationSort`. Authorization is automatic.
3. **Documents** (rows in `sales_documents`, `purchase_documents`, `stock_documents`, `vouchers`) use the
   `IsDocumentResource` trait (`$documentType`, `$documentService`, `$printType`) and pages extending
   `CreateDocumentRecord` / `EditDocumentRecord` / `ViewDocumentRecord`. A form field named `post_now`
   (dehydrated false) posts right after saving. Posted documents are read-only.
4. **Never put business logic in Filament classes.** Call the services. Catch `ErpException` and show a
   danger notification (see `DocumentActions`).
5. Numbers are assigned by model `creating` hooks (leave `number` blank for "auto").
   Master-data codes default to `DocumentNumber::nextCode(Model::class)`.
6. Small master data → single-page resource with `ManageRecords` (modal add/edit).
   Large forms (customers, suppliers, documents) → separate create/edit/view pages.
7. Bilingual masters have `name_ar` (required) and `name_en`; use `Fields::names()` and `Columns::name()`.
   Selects show `"code - name"` via `Fields::relation()`.
8. Common list filters: `Filters::year()`, `Filters::branch()`, `Filters::dateRange()`; posted tabs via `HasPostedTabs`.
9. Tests: extend `Tests\ErpTestCase` (seeds the chart of accounts and an admin). `PanelSmokeTest`
   renders every page. Run `php artisan test --compact`.

## Posting rules (perpetual inventory)

| Document | Debit | Credit | Stock |
|---|---|---|---|
| Sales invoice | customer / cash / card (grand total), COGS | sales (net or gross + discount account), output VAT, inventory | out |
| Sales return | reverse of invoice | | in |
| Purchase invoice | inventory (net + allocated expenses), input VAT | supplier / L/C account / cash | in (qty + bonus) |
| Purchase return | reverse | | out |
| Receipt voucher | header cash/bank account | line accounts | |
| Payment voucher | line accounts | header cash/bank account | |
| Cheque receipt | cheques under collection | line accounts | |
| Cheque collection | bank | cheques under collection | |
| Positive adjustment / stocktake surplus | inventory | adjustment account | in |
| Negative adjustment / stocktake shortage | adjustment account | inventory | out |
| Transfers | — | — | out at source, in at destination (inbound draft auto-created) |
| Customer/supplier opening balance | party account / opening balances | opening balances / party account | |
