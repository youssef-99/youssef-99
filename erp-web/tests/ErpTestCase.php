<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Base for ERP feature tests: fresh DB with the system seed (chart of accounts,
 * default accounts, branch, warehouse) and a logged-in admin.
 */
abstract class ErpTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(SystemSeeder::class);
        Cache::forget('erp.settings');
        $this->admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->actingAs($this->admin);
    }

    protected function seedDemo(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs($this->admin);
    }
}
