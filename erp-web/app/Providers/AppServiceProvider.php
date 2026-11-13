<?php

namespace App\Providers;

use App\Models\AccreditationFee;
use App\Models\ChequeCollection;
use App\Models\Customer;
use App\Models\FinancingContract;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Voucher;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Each module keeps its own Arabic translations in lang/modules/<module>/ar.json
        foreach (glob(lang_path('modules/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $this->app['translator']->addJsonPath($dir);
        }

        // Write labels in English; they are translated through the JSON files above.
        Field::configureUsing(fn (Field $field) => $field->translateLabel());
        Column::configureUsing(fn (Column $column) => $column->translateLabel());
        BaseFilter::configureUsing(fn (BaseFilter $filter) => $filter->translateLabel());
        Entry::configureUsing(fn (Entry $entry) => $entry->translateLabel());
        Action::configureUsing(fn (Action $action) => $action->translateLabel());

        Table::configureUsing(fn (Table $table) => $table
            ->striped()
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25));

        // Short morph names for polymorphic source columns (journal entries, stock movements).
        Relation::enforceMorphMap([
            'user' => User::class,
            'sales_document' => SalesDocument::class,
            'purchase_document' => PurchaseDocument::class,
            'stock_document' => StockDocument::class,
            'voucher' => Voucher::class,
            'cheque_collection' => ChequeCollection::class,
            'journal_entry' => JournalEntry::class,
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'accreditation_fee' => AccreditationFee::class,
            'financing_contract' => FinancingContract::class,
            'item' => Item::class,
        ]);
    }
}
