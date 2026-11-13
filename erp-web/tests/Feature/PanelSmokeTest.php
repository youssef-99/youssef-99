<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\ErpTestCase;

/**
 * Renders every resource index/create page and every custom page as an admin.
 */
class PanelSmokeTest extends ErpTestCase
{
    public function test_every_page_renders(): void
    {
        $this->seedDemo();
        $panel = Filament::getPanel('admin');
        $failures = [];

        foreach ($panel->getResources() as $resource) {
            foreach (['index', 'create'] as $page) {
                if (! $resource::hasPage($page)) {
                    continue;
                }
                $url = $resource::getUrl($page);
                $status = $this->get($url)->status();
                if ($status !== 200) {
                    $failures[] = "{$status} {$url}";
                }
            }

            $record = $resource::getEloquentQuery()->first();
            if ($record) {
                foreach (['view', 'edit'] as $page) {
                    if ($resource::hasPage($page) && ($page !== 'edit' || $resource::canEdit($record))) {
                        $url = $resource::getUrl($page, ['record' => $record]);
                        $status = $this->get($url)->status();
                        if ($status !== 200) {
                            $failures[] = "{$status} {$url}";
                        }
                    }
                }
            }
        }

        foreach ($panel->getPages() as $page) {
            if (method_exists($page, 'getUrl') && $page::canAccess()) {
                $url = $page::getUrl();
                $status = $this->get($url)->status();
                if ($status !== 200) {
                    $failures[] = "{$status} {$url}";
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_login_page_renders_in_arabic_rtl(): void
    {
        auth()->logout();
        $this->get('/admin/login')->assertOk()->assertSee('dir="rtl"', false);
    }
}
