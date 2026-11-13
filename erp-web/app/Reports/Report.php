<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Support\ReportLang;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Base class of every report (التقارير).
 *
 * HOW TO ADD A REPORT
 * -------------------
 * 1. Create `app/Reports/<Group>/<Name>Report.php` extending this class (any sub-directory of
 *    app/Reports except `Support` is scanned; the class is discovered automatically by ReportRegistry).
 * 2. Set `$group` and `$slug` to the catalog entry in {@see ReportRegistry::CATALOG}
 *    (URL: /admin/reports/<group>/<slug>, permission "reports.<group>.<slug>.view") and `$title` (English).
 * 3. `filters()`: return Filament form fields (flat list). Use {@see Support\ReportFields} helpers
 *    (fromDate(), toDate(), account(), customer(), branch(), reportType()...). Mark required ones `->required()`:
 *    they get the * in the form and a `required` rule in the viewer. Field labels are passed through
 *    {@see ReportLang} by the helpers; for your own fields use `->label(static::t('...'))`.
 * 4. `defaults()`: initial filter values (e.g. ['from' => ReportFields::fiscalYearStart(), 'to' => today]).
 * 5. `run(array $filters)`: build and return a {@see ReportResult}: columns (ReportColumn::text/money/number/date),
 *    rows (addRow / addGroup / addSubtotal), footer (addTotal), info lines (period(), info()).
 *    Keep queries here or in `app/Reports/Support` (e.g. {@see Support\Ledger} for posted journal lines,
 *    {@see Support\StatementBuilder}, {@see Support\TrialBalanceBuilder}, {@see Support\AgingBuilder}).
 * 6. Add the Arabic for every new string to `lang/modules/reports/ar.json` as "rpt.<English>".
 * Nothing else is needed: the hub card, filter page, viewer, print and XLSX / CSV export work for every report.
 */
abstract class Report
{
    protected static string $group;

    protected static string $slug;

    protected static string $title;

    /** "<group>/<slug>", e.g. "accounts/account-statement". */
    public static function key(): string
    {
        return static::$group.'/'.static::$slug;
    }

    public static function group(): string
    {
        return static::$group;
    }

    public static function slug(): string
    {
        return static::$slug;
    }

    public static function title(): string
    {
        return ReportLang::get(static::$title);
    }

    /** Permission page key; the user needs "{key}.view". */
    public static function permissionKey(): string
    {
        return 'reports.'.static::$group.'.'.static::$slug;
    }

    public static function canView(?User $user = null): bool
    {
        $user ??= auth()->user();

        return (bool) $user?->hasPermission(static::permissionKey().'.view');
    }

    /** @return array<int, Field> */
    public function filters(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [];
    }

    /**
     * Validation rules for the viewer query string, derived from filters():
     * required fields → required, dates → date, multiple selects → array, numeric inputs → numeric,
     * and "to" must not be before "from".
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->filters() as $field) {
            if (! $field instanceof Field) {
                continue;
            }
            $name = $field->getName();
            $fieldRules = [$field->isRequired() ? 'required' : 'nullable'];
            if ($field instanceof DatePicker) {
                $fieldRules[] = 'date';
            } elseif ($field instanceof Select && $field->isMultiple()) {
                $fieldRules[] = 'array';
            } elseif ($field instanceof TextInput && $field->isNumeric()) {
                $fieldRules[] = 'numeric';
            }
            $rules[$name] = $fieldRules;
        }

        if (isset($rules['from'], $rules['to'])) {
            $rules['to'][] = 'after_or_equal:from';
        }

        return $rules;
    }

    /** @return array<string, string> attribute names for validation messages */
    public function attributeLabels(): array
    {
        $labels = [];
        foreach ($this->filters() as $field) {
            if ($field instanceof Field) {
                $labels[$field->getName()] = (string) $field->getLabel();
            }
        }

        return $labels;
    }

    /** @param  array<string, mixed>  $filters  validated filter values (defaults merged) */
    abstract public function run(array $filters): ReportResult;

    /** Translate a report string (see ReportLang). */
    protected static function t(string $text, array $replace = []): string
    {
        return ReportLang::get($text, $replace);
    }

    /** A result pre-filled with this report's title. */
    protected function result(?string $subtitle = null): ReportResult
    {
        return ReportResult::make(static::title(), $subtitle);
    }
}
