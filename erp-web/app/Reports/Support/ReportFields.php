<?php

namespace App\Reports\Support;

use App\Models\Account;
use App\Models\Accreditation;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\FinancingContract;
use App\Models\FiscalYear;
use App\Models\Salesman;
use App\Models\Supplier;
use App\Models\SupplierGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Model;

/**
 * Filter fields shared by the reports. Every helper returns an optional field; chain `->required()`.
 * Option lists are plain arrays (no Eloquent relationship), so the fields work on any page.
 */
class ReportFields
{
    public static function fiscalYearStart(): string
    {
        $year = FiscalYear::query()->where('start_date', '<=', today())->where('end_date', '>=', today())->first();

        return ($year?->start_date ?? today()->startOfYear())->toDateString();
    }

    public static function today(): string
    {
        return today()->toDateString();
    }

    public static function date(string $name, string $label): DatePicker
    {
        return DatePicker::make($name)
            ->label(ReportLang::get($label))
            ->native(false)
            ->format('Y-m-d')
            ->displayFormat('d/m/Y')
            ->closeOnDateSelection();
    }

    public static function fromDate(string $name = 'from', string $label = 'From Date'): DatePicker
    {
        return static::date($name, $label);
    }

    public static function toDate(string $name = 'to', string $label = 'To Date'): DatePicker
    {
        return static::date($name, $label);
    }

    /** @param  array<string|int, string>|\Closure  $options */
    public static function select(string $name, string $label, array|\Closure $options): Select
    {
        return Select::make($name)
            ->label(ReportLang::get($label))
            ->options($options)
            ->searchable();
    }

    /** Account select ("number - name"); parents included unless $postableOnly. */
    public static function account(string $name = 'account_id', string $label = 'Account', bool $postableOnly = false, ?\Closure $scope = null): Select
    {
        return static::select($name, $label, function () use ($postableOnly, $scope): array {
            return Account::query()
                ->when($postableOnly, fn ($q) => $q->where('is_postable', true))
                ->when($scope, fn ($q) => $scope($q))
                ->orderBy('number')
                ->get()
                ->mapWithKeys(fn (Account $a) => [$a->id => $a->number.' - '.$a->name])
                ->all();
        });
    }

    public static function branch(string $name = 'branch_id', string $label = 'Branch'): Select
    {
        return static::select($name, $label, fn (): array => static::options(Branch::class));
    }

    public static function customer(string $name = 'customer_id', string $label = 'Customer'): Select
    {
        return static::select($name, $label, fn (): array => static::options(Customer::class));
    }

    public static function customerGroup(string $name = 'customer_group_id', string $label = 'Customer Group'): Select
    {
        return static::select($name, $label, fn (): array => static::options(CustomerGroup::class));
    }

    public static function supplier(string $name = 'supplier_id', string $label = 'Supplier'): Select
    {
        return static::select($name, $label, fn (): array => static::options(Supplier::class));
    }

    public static function supplierGroup(string $name = 'supplier_group_id', string $label = 'Supplier Group'): Select
    {
        return static::select($name, $label, fn (): array => static::options(SupplierGroup::class));
    }

    public static function salesman(string $name = 'salesman_id', string $label = 'Salesman'): Select
    {
        return static::select($name, $label, fn (): array => static::options(Salesman::class));
    }

    public static function costCenter(string $name = 'cost_center_id', string $label = 'Cost Center'): Select
    {
        return static::select($name, $label, fn (): array => static::options(CostCenter::class));
    }

    public static function bank(string $name = 'bank_id', string $label = 'Bank'): Select
    {
        return static::select($name, $label, fn (): array => static::options(Bank::class));
    }

    public static function accreditation(string $name = 'accreditation_id', string $label = 'Letter of Credit'): Select
    {
        return static::select($name, $label, fn (): array => Accreditation::query()->orderBy('number')->pluck('number', 'id')->all());
    }

    public static function financingContract(string $name = 'financing_contract_id', string $label = 'Financing Contract'): Select
    {
        return static::select($name, $label, fn (): array => FinancingContract::query()->orderBy('number')->pluck('number', 'id')->all());
    }

    /** Summary / Detail (نوع التقرير). */
    public static function reportType(string $name = 'report_type', string $label = 'Report Type'): Select
    {
        return Select::make($name)
            ->label(ReportLang::get($label))
            ->options(['summary' => ReportLang::get('Summary'), 'detail' => ReportLang::get('Detail')]);
    }

    /** Account level (رقم المستوى) 1..max level in the chart. */
    public static function level(string $name = 'level', string $label = 'Level No.'): Select
    {
        return Select::make($name)
            ->label(ReportLang::get($label))
            ->options(function (): array {
                $max = max(1, (int) Account::query()->max('level'));

                return array_combine(range(1, $max), range(1, $max));
            });
    }

    /** Fiscal year select (السنة). */
    public static function year(string $name = 'year', string $label = 'Year'): Select
    {
        return Select::make($name)
            ->label(ReportLang::get($label))
            ->options(function (): array {
                $years = FiscalYear::query()->orderByDesc('year')->pluck('year', 'year')->all();

                return $years ?: [now()->year => now()->year];
            });
    }

    public static function integer(string $name, string $label, int $min = 1): TextInput
    {
        return TextInput::make($name)->label(ReportLang::get($label))->numeric()->integer()->minValue($min);
    }

    /**
     * "code - name" options for a bilingual master.
     *
     * @param  class-string<Model>  $model
     * @return array<int, string>
     */
    public static function options(string $model): array
    {
        return $model::query()->orderBy('code')->get()->mapWithKeys(fn (Model $m) => [$m->getKey() => $m->label])->all();
    }
}
