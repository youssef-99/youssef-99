<?php

namespace Tests\Feature\Deploy;

use Dotenv\Dotenv;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * deploy/setup-vm.sh --only-env: the production env file and the initial admin password, generated once.
 */
class SetupVmScriptTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The deploy scripts target Ubuntu.');
        }

        $this->root = sys_get_temp_dir().'/erp-web-setup-vm-test-'.Str::random(8);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_generates_a_production_env_file_and_an_admin_password_without_printing_them(): void
    {
        $result = $this->setupEnv('erp.example.com');

        $this->assertTrue($result->successful(), $result->errorOutput());
        $env = $this->parsedEnv();
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('false', $env['APP_DEBUG']);
        $this->assertSame('https://erp.example.com', $env['APP_URL']);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame($this->root.'/data/database/database.sqlite', $env['DB_DATABASE']);
        $this->assertSame('database', $env['SESSION_DRIVER']);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE']);
        $this->assertSame('database', $env['CACHE_STORE']);
        $this->assertSame('database', $env['QUEUE_CONNECTION']);
        $this->assertSame('daily', $env['LOG_STACK']);
        $this->assertSame('sandbox', $env['ZATCA_ENVIRONMENT']);
        $this->assertSame('ERP Web', $env['APP_NAME']);

        $this->assertStringStartsWith('base64:', $env['APP_KEY']);
        $this->assertTrue(Encrypter::supported(base64_decode(substr($env['APP_KEY'], 7), true), 'aes-256-cbc'));

        $password = trim(File::get($this->root.'/etc/initial-admin-password'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $password);

        $this->assertSame('640', $this->mode($this->root.'/etc/erp-web.env'));
        $this->assertSame('600', $this->mode($this->root.'/etc/initial-admin-password'));
        $this->assertStringNotContainsString(substr($env['APP_KEY'], 7), $result->output().$result->errorOutput());
        $this->assertStringNotContainsString($password, $result->output().$result->errorOutput());
    }

    public function test_rerun_keeps_the_secrets_and_only_updates_app_url_for_a_new_domain(): void
    {
        $this->setupEnv('erp.example.com');
        $firstEnv = File::get($this->root.'/etc/erp-web.env');
        $firstPassword = File::get($this->root.'/etc/initial-admin-password');

        $result = $this->setupEnv('new-name.uaenorth.cloudapp.azure.com');

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame(
            str_replace('APP_URL=https://erp.example.com', 'APP_URL=https://new-name.uaenorth.cloudapp.azure.com', $firstEnv),
            File::get($this->root.'/etc/erp-web.env'),
        );
        $this->assertSame($firstPassword, File::get($this->root.'/etc/initial-admin-password'));
    }

    public function test_rerun_with_the_same_domain_leaves_the_env_file_untouched(): void
    {
        $this->setupEnv('erp.example.com');
        $firstEnv = File::get($this->root.'/etc/erp-web.env');

        $result = $this->setupEnv('ERP.example.com');

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame($firstEnv, File::get($this->root.'/etc/erp-web.env'));
        $this->assertSame('', $result->output());
    }

    public function test_rejects_a_domain_that_is_not_a_plain_dns_name(): void
    {
        $result = $this->setupEnv('erp.example.com;touch /tmp/x');

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('invalid domain', $result->errorOutput());
        $this->assertFileDoesNotExist($this->root.'/etc/erp-web.env');
    }

    protected function setupEnv(string $domain): ProcessResult
    {
        return Process::env([
            'ERP_RUN_AS_SELF' => '1',
            'ERP_ETC_DIR' => $this->root.'/etc',
            'ERP_DATA_DIR' => $this->root.'/data',
            'ERP_APP_DIR' => $this->root.'/app',
        ])->run(['bash', base_path('deploy/setup-vm.sh'), $domain, '--only-env']);
    }

    /**
     * @return array<string, string>
     */
    protected function parsedEnv(): array
    {
        return Dotenv::parse(File::get($this->root.'/etc/erp-web.env'));
    }

    protected function mode(string $path): string
    {
        return substr(sprintf('%o', fileperms($path)), -3);
    }
}
