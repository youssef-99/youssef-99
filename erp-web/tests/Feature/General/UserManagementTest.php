<?php

namespace Tests\Feature\General;

use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\General\UserResource;
use App\Filament\Resources\General\UserResource\Pages\CreateUser;
use App\Filament\Resources\General\UserResource\Pages\EditUser;
use App\Filament\Resources\General\UserResource\Pages\ListUsers;
use App\Models\Branch;
use App\Models\Group;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\ErpTestCase;

class UserManagementTest extends ErpTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function userData(array $overrides = []): array
    {
        return array_merge([
            'username' => 'pharmacist1',
            'name' => 'صيدلي أول',
            'email' => 'pharmacist1@example.com',
            'group_id' => Group::query()->value('id'),
            'default_branch_id' => Branch::query()->value('id'),
            'locale' => 'ar',
            'is_active' => true,
            'password' => 'Secret-Pass-123',
            'password_confirmation' => 'Secret-Pass-123',
        ], $overrides);
    }

    public function test_user_is_created_with_a_hashed_password_and_can_log_in_with_the_username(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData())
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('username', 'pharmacist1')->firstOrFail();
        $this->assertNotSame('Secret-Pass-123', $user->password);
        $this->assertTrue(Hash::check('Secret-Pass-123', $user->password));
        $this->assertSame('2', $user->code);

        auth()->logout();

        Livewire::test(Login::class)
            ->fillForm(['email' => 'pharmacist1', 'password' => 'Secret-Pass-123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_is_required_and_confirmed_on_create(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['password' => null, 'password_confirmation' => null]))
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['password_confirmation' => 'Other-Pass-123']))
            ->call('create')
            ->assertHasFormErrors(['password' => 'confirmed']);

        Livewire::test(CreateUser::class)
            ->fillForm($this->userData(['username' => 'admin', 'group_id' => null]))
            ->call('create')
            ->assertHasFormErrors(['username' => 'unique', 'group_id' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'pharmacist1@example.com']);
    }

    public function test_editing_without_a_password_keeps_the_current_one(): void
    {
        $user = User::factory()->create(['code' => '50', 'password' => 'Old-Pass-123', 'group_id' => Group::query()->value('id')]);

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['name' => 'New Name', 'default_branch_id' => Branch::query()->value('id')])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertTrue(Hash::check('Old-Pass-123', $user->password));
    }

    public function test_reset_password_action_changes_the_password_and_ends_sessions(): void
    {
        $user = User::factory()->create(['password' => 'Old-Pass-123']);
        $this->insertSession($user);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('resetPassword')->table($user), [
                'password' => 'Brand-New-456',
                'password_confirmation' => 'Brand-New-456',
            ])
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('Brand-New-456', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_terminate_sessions_action_logs_the_user_out_everywhere(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user);
        $this->insertSession($user);
        $other = User::factory()->create();
        $this->insertSession($other);

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('terminateSessions')->table($this->admin))
            ->callAction(TestAction::make('terminateSessions')->table($user));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_users_list_filters_by_group(): void
    {
        $cashiers = Group::query()->create(['code' => '9', 'name_ar' => 'كاشير', 'permissions' => ['general.stores.view']]);
        $cashier = User::factory()->create(['group_id' => $cashiers->id]);

        Livewire::test(ListUsers::class)
            ->filterTable('group_id', $cashiers->id)
            ->assertCanSeeTableRecords([$cashier])
            ->assertCanNotSeeTableRecords([$this->admin]);
    }

    public function test_inactive_user_cannot_open_the_panel(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_my_profile_page_renders(): void
    {
        $this->get('/admin/profile')->assertOk();
    }

    protected function insertSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => str()->random(40),
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_non_admin_with_edit_permission_cannot_edit_or_delete_an_admin(): void
    {
        $managers = Group::query()->create(['code' => '8', 'name_ar' => 'مدراء', 'permissions' => ['general.users.view', 'general.users.edit', 'general.users.delete']]);
        $manager = User::factory()->create(['group_id' => $managers->id, 'is_admin' => false, 'is_active' => true]);
        $clerk = User::factory()->create(['group_id' => $managers->id, 'is_admin' => false, 'is_active' => true]);

        $this->actingAs($manager);

        $this->assertFalse(UserResource::canEdit($this->admin));
        $this->assertFalse(UserResource::canDelete($this->admin));
        $this->assertTrue(UserResource::canEdit($clerk));
    }
}
