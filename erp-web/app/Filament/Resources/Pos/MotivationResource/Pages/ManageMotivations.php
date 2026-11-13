<?php

namespace App\Filament\Resources\Pos\MotivationResource\Pages;

use App\Filament\Resources\Pos\MotivationResource;
use App\Models\Branch;
use App\Services\MotivationService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\HtmlString;

class ManageMotivations extends ManageRecords
{
    protected static string $resource = MotivationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('earned')
                ->label('Earned Incentives')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->schema([
                    DatePicker::make('from')->label('From Date')->default(now()->startOfMonth())->required()->native(false),
                    DatePicker::make('to')->label('To Date')->default(now())->required()->native(false),
                    Select::make('branch_id')->label('Branch')->options(fn () => Branch::query()->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->name])->all()),
                ])
                ->action(function (array $data) {
                    $rows = MotivationService::earned($data['from'], $data['to'], $data['branch_id'] ?? null);
                    Notification::make()
                        ->title(__('Earned Incentives'))
                        ->body(static::earnedTable($rows->all()))
                        ->info()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /** @param  array<int, array{user_name: string, quantity: float, sales: float, incentive: float}>  $rows */
    public static function earnedTable(array $rows): HtmlString
    {
        if ($rows === []) {
            return new HtmlString(e(__('No incentives in this period.')));
        }

        $html = '<table class="w-full text-sm"><thead><tr><th class="text-start">'.e(__('User')).'</th><th class="text-end">'.e(__('Quantity')).'</th><th class="text-end">'.e(__('Sales')).'</th><th class="text-end">'.e(__('Incentive')).'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($row['user_name']).'</td><td class="text-end">'.e((string) $row['quantity']).'</td><td class="text-end">'.e(number_format($row['sales'], 2)).'</td><td class="text-end font-bold">'.e(number_format($row['incentive'], 2)).'</td></tr>';
        }

        return new HtmlString($html.'</tbody></table>');
    }
}
