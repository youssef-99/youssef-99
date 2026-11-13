<?php

namespace App\Enums;

/**
 * Top-level ERP modules. Used for sidebar groups and the permission tree.
 */
enum Module: string
{
    case Dashboard = 'dashboard';
    case General = 'general';
    case Sales = 'sales';
    case Purchases = 'purchases';
    case Cash = 'cash';
    case Accounting = 'accounting';
    case Inventory = 'inventory';
    case Reports = 'reports';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => __('Dashboard'),
            self::General => __('General'),
            self::Sales => __('Sales'),
            self::Purchases => __('Purchases'),
            self::Cash => __('Cash Transactions'),
            self::Accounting => __('Accounting'),
            self::Inventory => __('Inventory'),
            self::Reports => __('Reports'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Dashboard => 'heroicon-o-home',
            self::General => 'heroicon-o-cog-6-tooth',
            self::Sales => 'heroicon-o-shopping-cart',
            self::Purchases => 'heroicon-o-truck',
            self::Cash => 'heroicon-o-banknotes',
            self::Accounting => 'heroicon-o-calculator',
            self::Inventory => 'heroicon-o-cube',
            self::Reports => 'heroicon-o-document-chart-bar',
        };
    }

    public function sort(): int
    {
        return array_search($this, self::cases(), true);
    }
}
