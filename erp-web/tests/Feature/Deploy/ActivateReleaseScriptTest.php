<?php

namespace Tests\Feature\Deploy;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * deploy/activate-release.sh against stub releases whose artisan only logs its commands
 * (ERP_NO_SYSTEMD, ERP_RUN_AS_SELF and a file:// health URL keep it inside a temp directory).
 */
class ActivateReleaseScriptTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('The deploy scripts target Ubuntu.');
        }

        $this->root = sys_get_temp_dir().'/erp-web-activate-test-'.Str::random(8);
        File::ensureDirectoryExists($this->root.'/app/releases');
        File::put($this->root.'/healthy', 'OK');
        $setup = Process::env($this->scriptEnv())
            ->run(['bash', base_path('deploy/setup-vm.sh'), 'erp.example.com', '--only-env']);
        $this->assertTrue($setup->successful(), $setup->errorOutput());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_first_install_seeds_the_system_data_once_with_the_initial_admin_password(): void
    {
        $tarball = $this->stubRelease('a');

        $result = $this->activate($tarball);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $release = $this->currentRelease();
        $this->assertMatchesRegularExpression('/^\d{14}-aaaaaaa$/', $release);
        $password = trim(File::get($this->root.'/etc/initial-admin-password'));
        $this->assertSame([
            "{$release} migrate -",
            "{$release} db:seed --class=Database\\Seeders\\SystemSeeder {$password}",
            "{$release} optimize -",
            "{$release} filament:optimize -",
            "{$release} queue:restart -",
        ], $this->artisanCalls());
        $this->assertFileExists($this->root.'/data/.system-seeded');

        $releaseDir = $this->root.'/app/releases/'.$release;
        $this->assertSame($this->root.'/etc/erp-web.env', readlink($releaseDir.'/.env'));
        $this->assertSame($this->root.'/data/storage', readlink($releaseDir.'/storage'));
        $this->assertSame($this->root.'/data/storage/app/public', readlink($releaseDir.'/public/storage'));
        $this->assertDirectoryExists($this->root.'/data/storage/framework/views');
        $this->assertFileExists($this->root.'/data/database/database.sqlite');
        $this->assertSame('660', substr(sprintf('%o', fileperms($this->root.'/data/database/database.sqlite')), -3));
        $this->assertStringNotContainsString($password, $result->output().$result->errorOutput());
    }

    public function test_later_deploys_switch_to_the_new_release_without_seeding_again(): void
    {
        $this->activate($this->stubRelease('a'));
        File::delete($this->root.'/artisan.log');

        $result = $this->activate($this->stubRelease('b'));

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertStringEndsWith('-bbbbbbb', $this->currentRelease());
        $this->assertSame(['migrate', 'optimize', 'filament:optimize', 'queue:restart'], $this->artisanCommands());
    }

    public function test_failed_migration_keeps_the_previous_release_serving(): void
    {
        $this->activate($this->stubRelease('a'));
        $previous = $this->currentRelease();

        $result = $this->activate($this->stubRelease('b'), ['ERP_STUB_FAIL' => 'migrate']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('migrations failed', $result->errorOutput());
        $this->assertSame($previous, $this->currentRelease());
        $this->assertSame([$previous], $this->releaseNames());
    }

    public function test_failed_health_check_switches_back_and_discards_the_new_release(): void
    {
        $this->activate($this->stubRelease('a'));
        $previous = $this->currentRelease();

        $result = $this->activate($this->stubRelease('b'), ['ERP_HEALTH_URL' => 'file://'.$this->root.'/missing']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString("switched back to {$previous}", $result->errorOutput());
        $this->assertSame($previous, $this->currentRelease());
        $this->assertSame([$previous], $this->releaseNames());
    }

    public function test_rollback_returns_to_the_previous_release_and_discards_the_current_one(): void
    {
        $this->activate($this->stubRelease('a'));
        $previous = $this->currentRelease();
        $this->activate($this->stubRelease('b'));

        $result = $this->runScript(['--rollback']);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame($previous, $this->currentRelease());
        $this->assertSame([$previous], $this->releaseNames());
    }

    public function test_rollback_without_an_older_release_fails_and_keeps_the_current_one(): void
    {
        $this->activate($this->stubRelease('a'));
        $current = $this->currentRelease();

        $result = $this->runScript(['--rollback']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('no release older than', $result->errorOutput());
        $this->assertSame($current, $this->currentRelease());
    }

    public function test_keeps_only_the_newest_five_releases(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $letter) {
            $this->assertTrue($this->activate($this->stubRelease($letter))->successful());
        }

        $this->assertSame(
            ['bbbbbbb', 'ccccccc', 'ddddddd', 'eeeeeee', 'fffffff'],
            array_map(fn (string $name): string => substr($name, -7), $this->releaseNames()),
        );
        $this->assertStringEndsWith('-fffffff', $this->currentRelease());
    }

    public function test_rejects_a_tarball_that_is_not_a_release(): void
    {
        File::ensureDirectoryExists($this->root.'/not-a-release');
        File::put($this->root.'/not-a-release/index.html', 'hello');
        Process::run(['tar', '-czf', $this->root.'/not-a-release.tar.gz', '-C', $this->root.'/not-a-release', '.'])->throw();

        $result = $this->activate($this->root.'/not-a-release.tar.gz');

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('is not a release', $result->errorOutput());
        $this->assertSame([], $this->releaseNames());
        $this->assertFileDoesNotExist($this->root.'/app/current');
    }

    /**
     * A release tarball whose REVISION is the given letter repeated and whose artisan logs
     * "<release dir> <command> <ERP_ADMIN_PASSWORD or ->" and fails for the command in ERP_STUB_FAIL.
     */
    protected function stubRelease(string $letter): string
    {
        $dir = $this->root.'/build-'.$letter;
        File::ensureDirectoryExists($dir.'/public');
        File::ensureDirectoryExists($dir.'/bootstrap/cache');
        File::ensureDirectoryExists($dir.'/storage/logs');
        File::put($dir.'/REVISION', str_repeat($letter, 40)."\n");
        File::put($dir.'/public/index.php', '<?php');
        File::put($dir.'/artisan', <<<'PHP'
            <?php
            $command = $argv[1] ?? '';
            $arguments = $command === 'db:seed' ? ' '.$argv[2] : '';
            file_put_contents(getenv('ERP_STUB_LOG'), basename(__DIR__)." {$command}{$arguments} ".(getenv('ERP_ADMIN_PASSWORD') ?: '-')."\n", FILE_APPEND);
            if ($command === getenv('ERP_STUB_FAIL')) {
                exit(1);
            }
            if ($command === 'optimize') {
                file_put_contents(__DIR__.'/bootstrap/cache/config.php', '<?php return [];');
            }
            PHP);

        $tarball = $this->root.'/release-'.$letter.'.tar.gz';
        Process::run(['tar', '-czf', $tarball, '-C', $dir, '.'])->throw();

        return $tarball;
    }

    /**
     * @param  array<string, string>  $env
     */
    protected function activate(string $tarball, array $env = []): ProcessResult
    {
        return $this->runScript([$tarball], $env);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    protected function runScript(array $arguments, array $env = []): ProcessResult
    {
        return Process::env([...$this->scriptEnv(), ...$env])
            ->timeout(120)
            ->run(['bash', base_path('deploy/activate-release.sh'), ...$arguments]);
    }

    /**
     * @return array<string, string>
     */
    protected function scriptEnv(): array
    {
        return [
            'ERP_APP_DIR' => $this->root.'/app',
            'ERP_DATA_DIR' => $this->root.'/data',
            'ERP_ETC_DIR' => $this->root.'/etc',
            'ERP_BACKUP_DIR' => $this->root.'/backups',
            'ERP_PHP' => PHP_BINARY,
            'ERP_NO_SYSTEMD' => '1',
            'ERP_RUN_AS_SELF' => '1',
            'ERP_HEALTH_URL' => 'file://'.$this->root.'/healthy',
            'ERP_HEALTH_RETRIES' => '0',
            'ERP_STUB_LOG' => $this->root.'/artisan.log',
            'ERP_STUB_FAIL' => '',
        ];
    }

    protected function currentRelease(): string
    {
        return basename((string) readlink($this->root.'/app/current'));
    }

    /**
     * @return list<string>
     */
    protected function releaseNames(): array
    {
        $names = array_map('basename', File::directories($this->root.'/app/releases'));
        sort($names);

        return array_values(array_filter($names, fn (string $name): bool => ! str_starts_with($name, '.')));
    }

    /**
     * @return list<string>
     */
    protected function artisanCalls(): array
    {
        return File::lines($this->root.'/artisan.log')->filter()->values()->all();
    }

    /**
     * @return list<string>
     */
    protected function artisanCommands(): array
    {
        return array_map(fn (string $line): string => explode(' ', $line)[1], $this->artisanCalls());
    }
}
