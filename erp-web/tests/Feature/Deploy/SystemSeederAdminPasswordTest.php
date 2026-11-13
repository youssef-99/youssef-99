<?php

namespace Tests\Feature\Deploy;

use App\Models\User;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The admin password SystemSeeder uses on a fresh install (deploy/activate-release.sh passes ERP_ADMIN_PASSWORD).
 * Extends TestCase rather than ErpTestCase, which already runs SystemSeeder in setUp.
 */
class SystemSeederAdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_falls_back_to_the_local_default_password_outside_production(): void
    {
        $this->seedSystemData();

        $this->assertTrue(Hash::check('password', $this->admin()->password));
    }

    public function test_admin_is_created_with_the_configured_initial_password(): void
    {
        config(['erp.initial_admin_password' => 'Vm-initial-Pw_1234']);

        $this->seedSystemData();

        $this->assertTrue(Hash::check('Vm-initial-Pw_1234', $this->admin()->password));
        $this->assertFalse(Hash::check('password', $this->admin()->password));
    }

    public function test_production_install_without_an_initial_password_is_rejected_and_creates_no_admin(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->seedSystemData();
            $this->fail('SystemSeeder created the admin user in production without ERP_ADMIN_PASSWORD.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ERP_ADMIN_PASSWORD', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['username' => 'admin']);
    }

    public function test_seeding_again_in_production_keeps_the_existing_admin_password(): void
    {
        config(['erp.initial_admin_password' => 'Vm-initial-Pw_1234']);
        $this->seedSystemData();
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['erp.initial_admin_password' => null]);

        $this->seedSystemData();

        $this->assertSame(1, User::query()->where('username', 'admin')->count());
        $this->assertTrue(Hash::check('Vm-initial-Pw_1234', $this->admin()->password));
    }

    /**
     * Runs the seeder the way activate-release.sh does (--force skips the production confirmation).
     */
    protected function seedSystemData(): void
    {
        $this->artisan('db:seed', ['--class' => SystemSeeder::class, '--force' => true])->assertSuccessful();
    }

    protected function admin(): User
    {
        return User::query()->where('username', 'admin')->firstOrFail();
    }
}
