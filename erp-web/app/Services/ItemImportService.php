<?php

namespace App\Services;

use App\Enums\ItemType;
use App\Exceptions\ErpException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\Unit;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Add Items (إضافة أصناف) — bulk import from an XLSX / CSV file.
 *
 * File columns (in order, an optional header row is skipped):
 *   code, barcode, name_ar, name_en, unit, sale price, purchase price, vat rate
 * The bulk fields (main category, item type, expiry date, agent, tax exemption reason) apply to every row.
 * Rows whose code already exists update that item.
 */
class ItemImportService
{
    public const COLUMNS = ['code', 'barcode', 'name_ar', 'name_en', 'unit', 'sale_price', 'purchase_price', 'vat_rate'];

    /**
     * @param  array{category_id: int|string, item_type: string, expiry_date?: string|null, agent_id?: int|string|null, tax_exemption_reason?: string|null}  $defaults
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public static function import(string $path, array $defaults, ?string $extension = null): array
    {
        if (empty($defaults['category_id']) || empty($defaults['item_type'])) {
            throw new ErpException(__('Select the main category and the item type.'));
        }

        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $rows = static::readRows($path, $extension);

        DB::transaction(function () use ($rows, $defaults, &$result) {
            foreach ($rows as $index => $values) {
                $rowNumber = $index + 1;
                if ($index === 0 && static::isHeader($values)) {
                    continue;
                }

                $row = static::mapRow($values);
                if (collect($row)->filter(fn ($v) => filled($v))->isEmpty()) {
                    continue;
                }

                $error = static::validateRow($row, $defaults);
                if ($error) {
                    $result['errors'][] = __('Row :row: :error', ['row' => $rowNumber, 'error' => $error]);
                    $result['skipped']++;

                    continue;
                }

                $vat = filled($row['vat_rate']) ? (float) $row['vat_rate'] : (float) config('erp.default_vat_rate', 15);
                $attributes = array_filter([
                    'barcode' => $row['barcode'],
                    'name_ar' => $row['name_ar'],
                    'name_en' => $row['name_en'],
                    'unit_id' => static::unitId($row['unit']),
                    'sale_price' => filled($row['sale_price']) ? round((float) $row['sale_price'], 4) : null,
                    'purchase_price' => filled($row['purchase_price']) ? round((float) $row['purchase_price'], 4) : null,
                ], fn ($v) => $v !== null && $v !== '') + [
                    'category_id' => (int) $defaults['category_id'],
                    'item_type' => $defaults['item_type'],
                    'item_nature' => $defaults['item_type'] === ItemType::Service->value ? 'service' : 'stock',
                    'agent_id' => filled($defaults['agent_id'] ?? null) ? (int) $defaults['agent_id'] : null,
                    'vat_rate' => $vat,
                    'tax_exemption_reason' => $vat == 0.0 ? $defaults['tax_exemption_reason'] : null,
                ];

                $item = filled($row['code']) ? Item::query()->where('code', $row['code'])->first() : null;
                if ($item) {
                    $item->update($attributes);
                    $result['updated']++;
                } else {
                    $item = Item::query()->create($attributes + [
                        'code' => filled($row['code']) ? $row['code'] : DocumentNumber::nextCode(Item::class),
                        'reference_no' => filled($row['code']) ? $row['code'] : null,
                    ]);
                    $result['created']++;
                }

                if (filled($defaults['expiry_date'] ?? null) && $item->isBatchTracked()) {
                    ItemBatch::query()->firstOrCreate(
                        ['item_id' => $item->id, 'batch_no' => '-', 'expiry_date' => Carbon::parse($defaults['expiry_date'])->toDateString()],
                        ['last_purchase_price' => (float) $item->purchase_price, 'sale_price' => (float) $item->sale_price],
                    );
                }
            }
        });

        return $result;
    }

    /** @return list<list<mixed>> */
    public static function readRows(string $path, ?string $extension = null): array
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));
        $reader = match ($extension) {
            'csv', 'txt' => new CsvReader,
            'xlsx' => new XlsxReader,
            default => throw new ErpException(__('Unsupported file type. Upload an XLSX or CSV file.')),
        };

        try {
            $reader->open($path);
        } catch (Throwable) {
            throw new ErpException(__('The file could not be read.'));
        }

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    /** @param list<mixed> $values */
    protected static function isHeader(array $values): bool
    {
        $first = mb_strtolower(trim((string) ($values[0] ?? '')));
        $third = mb_strtolower(trim((string) ($values[2] ?? '')));

        return in_array($first, ['code', 'item code', 'كود الصنف', 'الكود', 'كود'], true)
            || str_contains($third, 'name') || str_contains($third, 'اسم');
    }

    /**
     * @param  list<mixed>  $values
     * @return array<string, string|null>
     */
    protected static function mapRow(array $values): array
    {
        $row = [];
        foreach (self::COLUMNS as $i => $column) {
            $value = $values[$i] ?? null;
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
            if (is_float($value) && in_array($column, ['code', 'barcode'], true) && floor($value) === $value) {
                $value = number_format($value, 0, '.', '');
            }
            $value = $value === null ? null : trim((string) $value);
            $row[$column] = $value === '' ? null : $value;
        }

        return $row;
    }

    /** @param array<string, string|null> $row */
    protected static function validateRow(array $row, array $defaults): ?string
    {
        if (blank($row['name_ar'])) {
            return __('The Arabic name is required.');
        }
        foreach (['sale_price' => 'Selling Price', 'purchase_price' => 'Purchase Price', 'vat_rate' => 'VAT %'] as $column => $label) {
            if (filled($row[$column]) && (! is_numeric($row[$column]) || (float) $row[$column] < 0)) {
                return __(':field must be a positive number.', ['field' => __($label)]);
            }
        }
        if (filled($row['vat_rate']) && (float) $row['vat_rate'] > 100) {
            return __(':field must be a positive number.', ['field' => __('VAT %')]);
        }
        if (filled($row['vat_rate']) && (float) $row['vat_rate'] == 0.0 && blank($defaults['tax_exemption_reason'] ?? null)) {
            return __('A tax exemption reason is required for items with 0% VAT.');
        }
        if (filled($row['barcode']) && Item::query()->where('barcode', $row['barcode'])->when($row['code'], fn ($q, $code) => $q->where('code', '!=', $code))->exists()) {
            return __('The barcode :barcode is used by another item.', ['barcode' => $row['barcode']]);
        }

        return null;
    }

    protected static function unitId(?string $unit): ?int
    {
        if (blank($unit)) {
            return null;
        }

        return Unit::query()
            ->where('code', $unit)->orWhere('name_ar', $unit)->orWhere('name_en', $unit)
            ->value('id');
    }
}
