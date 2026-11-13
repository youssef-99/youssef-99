<?php

namespace Tests\Feature\General;

use App\Filament\Resources\General\GroupResource\Pages\CreateGroup;
use App\Filament\Resources\General\GroupResource\Pages\EditGroup;
use App\Filament\Resources\General\GroupResource\PermissionTree;
use App\Models\Group;
use App\Models\User;
use Livewire\Livewire;
use Tests\ErpTestCase;

class GroupPermissionsTest extends ErpTestCase
{
    public function test_permission_tree_is_built_from_the_panel_pages(): void
    {
        $tree = PermissionTree::tree();

        $this->assertArrayHasKey('general', $tree);
        $this->assertArrayHasKey('general.groups', $tree['general']['pages']);
        $this->assertArrayHasKey('general.groups.create', $tree['general']['pages']['general.groups']['actions']);
        $this->assertArrayHasKey('general.settings', $tree['general']['pages']);
    }

    public function test_group_is_saved_with_the_selected_permission_keys(): void
    {
        Livewire::test(CreateGroup::class)
            ->fillForm([
                'name_ar' => 'المحاسبون',
                'name_en' => 'Accountants',
                'tree' => ['general' => [
                    PermissionTree::fieldKey('general.branches') => ['general.branches.view', 'general.branches.edit'],
                    PermissionTree::fieldKey('general.users') => ['general.users.view'],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = Group::query()->where('name_en', 'Accountants')->firstOrFail();
        $this->assertEqualsCanonicalizing(['general.branches.view', 'general.branches.edit', 'general.users.view'], $group->permissions);
        $this->assertSame('2', $group->code);

        $user = User::factory()->create(['group_id' => $group->id]);
        $this->assertTrue($user->hasPermission('general.branches.edit'));
        $this->assertFalse($user->hasPermission('general.branches.delete'));
    }

    public function test_group_requires_at_least_one_permission(): void
    {
        Livewire::test(CreateGroup::class)
            ->fillForm(['name_ar' => 'بدون صلاحيات'])
            ->call('create')
            ->assertHasFormErrors(['select_all']);

        $this->assertDatabaseMissing('groups', ['name_ar' => 'بدون صلاحيات']);
    }

    public function test_select_all_grants_every_permission(): void
    {
        Livewire::test(CreateGroup::class)
            ->fillForm(['name_ar' => 'الكل'])
            ->set('data.select_all', true)
            ->call('create')
            ->assertHasNoFormErrors();

        $group = Group::query()->where('name_ar', 'الكل')->firstOrFail();
        $this->assertEqualsCanonicalizing(PermissionTree::knownPermissions(), $group->permissions);
    }

    public function test_editing_keeps_permissions_of_pages_not_in_the_tree(): void
    {
        $group = Group::query()->create([
            'code' => '5',
            'name_ar' => 'مجموعة',
            'permissions' => ['general.branches.view', 'legacy.page.view'],
        ]);

        Livewire::test(EditGroup::class, ['record' => $group->getRouteKey()])
            ->assertSchemaStateSet(['tree.general.'.PermissionTree::fieldKey('general.branches') => ['general.branches.view']])
            ->fillForm(['tree' => ['general' => [PermissionTree::fieldKey('general.branches') => ['general.branches.view', 'general.branches.delete']]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['general.branches.view', 'general.branches.delete', 'legacy.page.view'], $group->fresh()->permissions);
    }

    public function test_group_permissions_control_page_access(): void
    {
        $group = Group::query()->create(['code' => '6', 'name_ar' => 'المخازن', 'permissions' => ['general.stores.view']]);
        $user = User::factory()->create(['group_id' => $group->id]);
        $this->actingAs($user);

        $this->get('/admin/general/stores')->assertOk();
        $this->get('/admin/general/users')->assertForbidden();
        $this->get('/admin/general/settings')->assertForbidden();
    }
}
