<?php

namespace App\Enums;

enum StockDocumentType: string
{
    case PositiveAdjustment = 'positive_adjustment';
    case NegativeAdjustment = 'negative_adjustment';
    case OutboundTransfer = 'outbound_transfer';
    case InboundTransfer = 'inbound_transfer';
    case AnnualStocktake = 'annual_stocktake';
    case PeriodicStocktake = 'periodic_stocktake';

    public function label(): string
    {
        return __(match ($this) {
            self::PositiveAdjustment => 'Stock Increase Adjustment',
            self::NegativeAdjustment => 'Stock Decrease Adjustment',
            self::OutboundTransfer => 'Outbound Transfer',
            self::InboundTransfer => 'Inbound Transfer',
            self::AnnualStocktake => 'Annual Stocktake Record',
            self::PeriodicStocktake => 'Periodic Stocktake Record',
        });
    }

    public function isStocktake(): bool
    {
        return in_array($this, [self::AnnualStocktake, self::PeriodicStocktake], true);
    }
}
