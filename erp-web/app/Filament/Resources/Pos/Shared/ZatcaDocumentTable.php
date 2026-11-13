<?php

namespace App\Filament\Resources\Pos\Shared;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\SalesDocument;
use App\Services\Zatca\ZatcaService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared table for every ZATCA page (sales invoices / returns / credit notes, and the Cash module pages):
 * posted sales documents of the given types with Invoice Type, Submission Status and Error Message,
 * and the actions Generate / Sign, Submit / Resubmit, View XML, Download XML and bulk Submit.
 *
 * Usage: ZatcaDocumentTable::configure($table, static::class, ['cash_sales_invoice']);
 */
class ZatcaDocumentTable
{
    /**
     * @param  string  $resource  the ErpResource class (submit actions need its "post" permission)
     * @param  array<int, string>  $types  sales_documents.type values
     * @param  array<int, mixed>  $extraColumns  columns inserted after the date
     */
    public static function configure(Table $table, string $resource, array $types, array $extraColumns = []): Table
    {
        $canSubmit = fn () => $resource::userCan(Permission::Post);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('type', $types)->where('is_posted', true)->with('branch', 'customer', 'cashCustomer'))
            ->columns([
                TextColumn::make('number')->label('Invoice No.')->searchable()->sortable(),
                Columns::date(label: 'Invoice Date'),
                ...$extraColumns,
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (SalesDocument $record) => $record->branch?->name)->toggleable(),
                TextColumn::make('customer.name_ar')->label('Customer')
                    ->formatStateUsing(fn (SalesDocument $record) => $record->customer?->name ?? $record->cashCustomer?->name ?? '-')
                    ->placeholder('-')->toggleable(),
                Columns::money('tax_total', 'Tax'),
                Columns::money('grand_total', 'Net After Tax'),
                TextColumn::make('zatca_invoice_type')->label('Invoice Type')->badge()
                    ->formatStateUsing(fn (?string $state) => static::invoiceTypes()[$state] ?? $state)
                    ->color(fn (?string $state) => $state === 'standard' ? 'info' : 'gray')
                    ->placeholder('-'),
                TextColumn::make('zatca_status')->label('Submission Status')->badge()
                    ->formatStateUsing(fn (?string $state) => static::statuses()[$state] ?? $state)
                    ->color(fn (?string $state) => ZatcaService::statusColor($state))
                    ->placeholder(__('Not Generated')),
                TextColumn::make('zatca_error')->label('Error Message')->limit(80)->tooltip(fn (SalesDocument $record) => $record->zatca_error)->wrap()->placeholder('-'),
                TextColumn::make('zatca_icv')->label('Invoice Counter (ICV)')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('zatca_submitted_at')->label('Submitted At')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filters::year(),
                Filters::branch(),
                SelectFilter::make('zatca_status')->label('Submission Status')->options(static::statuses()),
                SelectFilter::make('zatca_invoice_type')->label('Invoice Type')->options(static::invoiceTypes()),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('zatcaGenerate')
                        ->label('Generate / Sign')
                        ->icon('heroicon-o-finger-print')
                        ->visible(fn (SalesDocument $record) => $canSubmit() && ! in_array($record->zatca_status, ZatcaService::ACCEPTED, true))
                        ->action(function (SalesDocument $record) {
                            app(ZatcaService::class)->prepare($record);
                            Notification::make()->success()->title(__('The e-invoice was generated and signed.'))->send();
                        }),
                    Action::make('zatcaSubmit')
                        ->label(fn (SalesDocument $record) => in_array($record->zatca_status, ['rejected', 'error'], true) ? 'Resubmit' : 'Submit')
                        ->icon('heroicon-o-paper-airplane')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (SalesDocument $record) => $canSubmit() && app(ZatcaService::class)->canSubmit($record))
                        ->action(fn (SalesDocument $record) => static::notifyResult(static::send($record))),
                    Action::make('zatcaViewXml')
                        ->label('View XML')
                        ->icon('heroicon-o-code-bracket')
                        ->color('gray')
                        ->visible(fn (SalesDocument $record) => filled($record->zatca_xml))
                        ->modalHeading(fn (SalesDocument $record) => __('XML').' #'.$record->number)
                        ->modalContent(fn (SalesDocument $record) => new HtmlString(
                            '<pre dir="ltr" style="max-height:60vh;overflow:auto;font-size:12px;white-space:pre-wrap">'.e((string) $record->zatca_xml).'</pre>'
                        ))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close')),
                    Action::make('zatcaDownloadXml')
                        ->label('Download XML')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('gray')
                        ->visible(fn (SalesDocument $record) => filled($record->zatca_xml))
                        ->action(fn (SalesDocument $record) => static::download($record)),
                ])->label(__('Actions')),
            ])
            ->toolbarActions([
                BulkAction::make('zatcaSubmitSelected')
                    ->label('Submit to ZATCA')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible($canSubmit)
                    ->action(function (Collection $records) {
                        $counts = ['ok' => 0, 'failed' => 0];
                        foreach ($records as $record) {
                            if (! app(ZatcaService::class)->canSubmit($record)) {
                                continue;
                            }
                            $result = static::send($record);
                            $counts[in_array($result->zatca_status, ZatcaService::ACCEPTED, true) ? 'ok' : 'failed']++;
                        }
                        Notification::make()
                            ->title(__(':ok accepted, :failed failed', $counts))
                            ->color($counts['failed'] ? 'warning' : 'success')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function send(SalesDocument $record): SalesDocument
    {
        $service = app(ZatcaService::class);

        try {
            return in_array($record->zatca_status, ['rejected', 'error'], true) ? $service->resubmit($record) : $service->submit($record);
        } catch (ErpException $e) {
            $record->zatca_error = $e->getMessage();

            return $record;
        }
    }

    public static function notifyResult(SalesDocument $record): void
    {
        $accepted = in_array($record->zatca_status, ZatcaService::ACCEPTED, true);
        Notification::make()
            ->title($accepted ? __('Sent to ZATCA') : __('ZATCA submission failed'))
            ->body($record->zatca_error)
            ->color($accepted ? 'success' : 'danger')
            ->persistent(! $accepted)
            ->send();
    }

    public static function download(SalesDocument $record): StreamedResponse
    {
        $name = str_replace(['/', '\\', ' '], '-', ($record->branch?->company?->vat_no ?: 'invoice').'_'.$record->date->format('Ymd').'_'.$record->number).'.xml';

        return response()->streamDownload(function () use ($record) {
            echo $record->zatca_xml;
        }, $name, ['Content-Type' => 'application/xml']);
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return array_map(fn (string $label) => __($label), ZatcaService::STATUSES);
    }

    /** @return array<string, string> */
    public static function invoiceTypes(): array
    {
        return ['standard' => __('Standard (B2B)'), 'simplified' => __('Simplified (B2C)')];
    }
}
