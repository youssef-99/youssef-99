<?php

namespace App\Reports\Support;

use App\Models\Branch;
use App\Models\Doctor;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemGroup;
use App\Models\Manufacturer;
use App\Models\PointsCustomer;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Filter fields of the inventory, sales, salesmen and purchase reports (in addition to {@see ReportFields}).
 * Every helper returns an optional field; chain `->required()`.
 */
class TradeFields
{
    /** Branches (الفروع): multiple select, empty = all branches. */
    public static function branches(string $name = 'branch_ids', string $label = 'Branches'): Select
    {
        return ReportFields::select($name, $label, fn (): array => ReportFields::options(Branch::class))->multiple();
    }

    public static function warehouse(string $name = 'warehouse_id', string $label = 'Warehouse'): Select
    {
        return ReportFields::select($name, $label, fn (): array => ReportFields::options(Warehouse::class));
    }

    /** Item select; the option list is searched in the database ("code - name", barcode). */
    public static function item(string $name = 'item_id', string $label = 'Item'): Select
    {
        return Select::make($name)
            ->label(ReportLang::get($label))
            ->searchable()
            ->getSearchResultsUsing(fn (?string $search): array => Item::query()->searchName($search)->orderBy('code')->limit(50)->get()
                ->mapWithKeys(fn (Item $item) => [$item->id => $item->label])->all())
            ->getOptionLabelUsing(fn ($value): ?string => Item::query()->find($value)?->label);
    }

    /** Several items (searchable). */
    public static function items(string $name = 'item_ids', string $label = 'Items'): Select
    {
        return static::item($name, $label)
            ->multiple()
            ->getOptionLabelsUsing(fn (array $values): array => Item::query()->whereKey($values)->get()
                ->mapWithKeys(fn (Item $item) => [$item->id => $item->label])->all());
    }

    public static function category(string $name = 'category_id', string $label = 'Item Category'): Select
    {
        return ReportFields::select($name, $label, fn (): array => ReportFields::options(ItemCategory::class));
    }

    public static function itemGroup(string $name = 'group_id', string $label = 'Item Group'): Select
    {
        return ReportFields::select($name, $label, fn (): array => ReportFields::options(ItemGroup::class));
    }

    public static function manufacturer(string $name = 'manufacturer_id', string $label = 'Manufacturer'): Select
    {
        return ReportFields::select($name, $label, fn (): array => ReportFields::options(Manufacturer::class));
    }

    public static function user(string $name = 'user_id', string $label = 'User'): Select
    {
        return ReportFields::select($name, $label, fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all());
    }

    public static function doctor(string $name = 'doctor_id', string $label = 'Doctor'): Select
    {
        return ReportFields::select($name, $label, fn (): array => ReportFields::options(Doctor::class));
    }

    public static function promotion(string $name = 'promotion_id', string $label = 'Promotion'): Select
    {
        return ReportFields::select($name, $label, fn (): array => ReportFields::options(Promotion::class));
    }

    public static function pointsCustomer(string $name = 'points_customer_id', string $label = 'Points Customer'): Select
    {
        return ReportFields::select($name, $label, fn (): array => PointsCustomer::query()->orderBy('code')->get()
            ->mapWithKeys(fn (PointsCustomer $customer) => [$customer->id => $customer->label])->all());
    }

    /**
     * Fixed choice; option labels are English and translated through {@see ReportLang}.
     *
     * @param  array<string, string>  $options
     */
    public static function choice(string $name, string $label, array $options): Select
    {
        return Select::make($name)
            ->label(ReportLang::get($label))
            ->options(array_map(fn (string $text) => ReportLang::get($text), $options));
    }

    /** Amounts with / without VAT (القيم شاملة الضريبة؟). */
    public static function taxMode(string $name = 'tax_mode', string $label = 'Amounts'): Select
    {
        return static::choice($name, $label, ['without' => 'Excluding VAT', 'with' => 'Including VAT']);
    }

    public static function number(string $name, string $label): TextInput
    {
        return TextInput::make($name)->label(ReportLang::get($label))->numeric();
    }

    public static function checkbox(string $name, string $label): Checkbox
    {
        return Checkbox::make($name)->label(ReportLang::get($label));
    }

    /**
     * Branch ids from the filters (`branch_ids` multiple or `branch_id` single); empty = all.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    public static function branchIds(array $filters): array
    {
        $ids = $filters['branch_ids'] ?? ($filters['branch_id'] ?? []);

        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    /**
     * "Branches: A, B" info value, or null for all branches.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function branchNames(array $filters): ?string
    {
        $ids = static::branchIds($filters);

        return $ids ? Branch::query()->whereKey($ids)->orderBy('code')->get()->map->name->implode('، ') : null;
    }

    /** Is a checkbox / flag filter on? */
    public static function flag(array $filters, string $name): bool
    {
        return filter_var($filters[$name] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
