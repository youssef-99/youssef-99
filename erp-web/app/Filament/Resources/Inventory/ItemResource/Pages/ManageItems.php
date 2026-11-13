<?php

namespace App\Filament\Resources\Inventory\ItemResource\Pages;

use App\Enums\ItemType;
use App\Enums\Permission;
use App\Enums\TaxExemptionReason;
use App\Exceptions\ErpException;
use App\Filament\Resources\Inventory\ItemResource;
use App\Models\Agent;
use App\Models\ItemCategory;
use App\Services\ItemImportService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageItems extends ManageRecords
{
    protected static string $resource = ItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add Item'))->modalWidth('5xl'),
            $this->importAction(),
            Action::make('downloadTemplate')
                ->label('Items File Template')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(function () {
                    echo "\xEF\xBB\xBF".implode(',', ItemImportService::COLUMNS)."\n";
                    echo "1001,6281001000011,بنادول 500 مجم,Panadol 500mg,1,12.00,8.50,15\n";
                }, 'items-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8'])),
        ];
    }

    /** Add Items (إضافة أصناف): bulk add from an XLSX / CSV file. */
    protected function importAction(): Action
    {
        return Action::make('importItems')
            ->label('Add Items')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn () => ItemResource::userCan(Permission::Create))
            ->modalDescription(__('File columns: code, barcode, name_ar, name_en, unit, sale price, purchase price, vat rate. Existing codes are updated.'))
            ->schema([
                FileUpload::make('file')
                    ->label('Items File')
                    ->disk('local')
                    ->directory('imports')
                    ->acceptedFileTypes([
                        'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
                Select::make('category_id')->label('Main Category')
                    ->options(fn () => ItemCategory::query()->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn (ItemCategory $c) => [$c->id => $c->label]))
                    ->searchable()
                    ->required(),
                Select::make('item_type')->label('Item Type')->options(ItemType::options())->default(ItemType::BatchExpiry->value)->required(),
                DatePicker::make('expiry_date')->label('Expiry Date')->native(false)->displayFormat('m/Y'),
                Select::make('agent_id')->label('Agent')
                    ->options(fn () => Agent::query()->orderBy('code')->get()->mapWithKeys(fn (Agent $a) => [$a->id => $a->label]))
                    ->searchable(),
                Select::make('tax_exemption_reason')->label('Tax Exemption Reason')
                    ->helperText(__('Applied to rows with 0% VAT.'))
                    ->options(TaxExemptionReason::options())
                    ->default(TaxExemptionReason::Medicines->value)
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data, Action $action) {
                $path = Storage::disk('local')->path($data['file']);

                try {
                    $result = ItemImportService::import($path, $data, Str::afterLast($data['file'], '.'));
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Import failed'))->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                } finally {
                    Storage::disk('local')->delete($data['file']);
                }

                Notification::make()
                    ->title(__(':created items added, :updated updated, :skipped skipped', ['created' => $result['created'], 'updated' => $result['updated'], 'skipped' => $result['skipped']]))
                    ->body(implode("\n", array_slice($result['errors'], 0, 20)))
                    ->color($result['errors'] ? 'warning' : 'success')
                    ->persistent((bool) $result['errors'])
                    ->send();
            });
    }
}
