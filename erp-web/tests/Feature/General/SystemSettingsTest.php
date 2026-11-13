<?php

namespace Tests\Feature\General;

use App\Filament\Pages\General\SystemSettings;
use App\Models\Account;
use App\Services\Settings;
use Livewire\Livewire;
use Tests\ErpTestCase;

class SystemSettingsTest extends ErpTestCase
{
    public function test_settings_page_shows_and_saves_default_accounts_and_options(): void
    {
        $bank = Account::query()->where('number', '110103')->firstOrFail();

        $this->get('/admin/general/settings')->assertOk();

        Livewire::test(SystemSettings::class)
            ->assertSchemaStateSet(['accounts.cash' => Settings::account('cash')])
            ->fillForm([
                'accounts' => ['cash' => $bank->id],
                'vat' => ['default_rate' => 5, 'prices_include_tax' => true],
                'zatca' => ['enabled' => false, 'environment' => 'simulation', 'submission_mode' => 'manual'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($bank->id, Settings::account('cash'));
        $this->assertSame('5', Settings::get('vat.default_rate'));
        $this->assertSame('1', Settings::get('vat.prices_include_tax'));
        $this->assertSame('0', Settings::get('zatca.enabled'));
        $this->assertSame('simulation', Settings::get('zatca.environment'));
        $this->assertNotNull(Settings::account('sales'));
    }

    public function test_parent_accounts_are_rejected_for_posting_accounts(): void
    {
        $parent = Account::query()->where('number', '1101')->firstOrFail();
        $before = Settings::account('cash');

        Livewire::test(SystemSettings::class)
            ->fillForm(['accounts' => ['cash' => $parent->id, 'receivables' => Account::query()->where('number', '1102')->value('id')]])
            ->call('save')
            ->assertHasFormErrors(['accounts.cash']);

        $this->assertSame($before, Settings::account('cash'));
    }
}
