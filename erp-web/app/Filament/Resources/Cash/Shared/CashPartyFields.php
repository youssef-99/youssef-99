<?php

namespace App\Filament\Resources\Cash\Shared;

use App\Filament\Support\Fields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fields shared by cash suppliers and cash customers.
 */
class CashPartyFields
{
    public static function vatNo(): TextInput
    {
        return TextInput::make('vat_no')->label('VAT No.')->maxLength(15)->regex('/^3\d{13}3$/')
            ->validationMessages(['regex' => __('The VAT number must be 15 digits, starting and ending with 3.')]);
    }

    /** @return array<int, TextInput> */
    public static function address(): array
    {
        return [
            TextInput::make('address')->label('Address')->maxLength(255),
            TextInput::make('street_name')->label('Street Name')->maxLength(255),
            TextInput::make('building_no')->label('Building No.')->maxLength(20),
            TextInput::make('additional_no')->label('Additional Address No.')->maxLength(20),
            TextInput::make('postal_code')->label('Postal Code')->maxLength(10),
        ];
    }

    /** @return array<int, TextInput> */
    public static function phones(): array
    {
        return [
            TextInput::make('phone')->label('Phone')->tel()->maxLength(30),
            TextInput::make('mobile')->label('Mobile')->tel()->maxLength(30),
        ];
    }

    /** Country → City / Region (both filtered by country). @return array<int, Select> */
    public static function geography(bool $required): array
    {
        return [
            Fields::relation('country_id', 'country', 'Country')
                ->required($required)
                ->live()
                ->afterStateUpdated(function (Set $set) {
                    $set('city_id', null);
                    $set('region_id', null);
                }),
            Fields::relation('city_id', 'city', 'City', modifyQuery: fn (Builder $query, Get $get) => $query->when($get('country_id'), fn ($q, $c) => $q->where('country_id', $c)))
                ->required($required),
            Fields::relation('region_id', 'region', 'Region', modifyQuery: fn (Builder $query, Get $get) => $query->when($get('country_id'), fn ($q, $c) => $q->where('country_id', $c)))
                ->required($required),
        ];
    }
}
