<?php

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Migration\MigrationStatus;
use Phunkie\Phetch\Migration\Migrator;
use RuntimeException;

use function Phunkie\Phetch\Functions\connect;

class MigratorTest extends TestCase
{
    private Connection $conn;
    private string $path;
    private Migrator $migrator;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers())) {
            $this->markTestSkipped('SQLite PDO driver not available');
        }

        $this->conn = connect('sqlite::memory:')->unsafeRun();
        $this->path = sys_get_temp_dir() . '/phetch-migrations-' . uniqid();
        mkdir($this->path);
        $this->migrator = new Migrator($this->path);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->path . '/*.php'));
        rmdir($this->path);
    }

    public function test_runs_and_rolls_back_a_migration_it_generated()
    {
        ob_start();
        $this->migrator->make('CreateWidgets')->unsafeRun();
        ob_end_clean();

        $file = glob($this->path . '/*_CreateWidgets.php')[0];
        file_put_contents($file, str_replace(
            ['CREATE TABLE ...', 'DROP TABLE ...'],
            ['CREATE TABLE widgets (id INTEGER PRIMARY KEY)', 'DROP TABLE widgets'],
            file_get_contents($file)
        ));

        ob_start();
        $this->migrator->run()->run($this->conn)->unsafeRun();
        ob_end_clean();

        $this->assertSame(['migrations', 'widgets'], $this->tables());
        $this->assertSame([basename($file, '.php')], $this->logged());

        ob_start();
        $this->migrator->rollback()->run($this->conn)->unsafeRun();
        ob_end_clean();

        $this->assertSame(['migrations'], $this->tables());
        $this->assertSame([], $this->logged());
    }

    public function test_status_lists_every_file_with_its_batch()
    {
        $this->writeMigration('2026_01_01_000001_CreateGizmos', 'CreateGizmos', 'CREATE TABLE gizmos (id INTEGER PRIMARY KEY)', 'DROP TABLE gizmos');
        ob_start();
        $this->migrator->run()->run($this->conn)->unsafeRun();
        ob_end_clean();
        $this->writeMigration('2026_01_01_000002_CreateGadgets', 'CreateGadgets', 'CREATE TABLE gadgets (id INTEGER PRIMARY KEY)', 'DROP TABLE gadgets');

        $status = $this->migrator->status()->run($this->conn)->unsafeRun();

        $this->assertEquals(
            ImmList(
                new MigrationStatus('2026_01_01_000001_CreateGizmos', 1),
                new MigrationStatus('2026_01_01_000002_CreateGadgets', null)
            ),
            $status
        );
    }

    public function test_generated_migrations_import_io_instead_of_inlining_its_namespace()
    {
        ob_start();
        $this->migrator->make('CreateSprockets')->unsafeRun();
        ob_end_clean();

        $source = file_get_contents(glob($this->path . '/*_CreateSprockets.php')[0]);

        $this->assertStringContainsString('use function Phunkie\\Effect\\Functions\\io\\io;', $source);
        $this->assertStringNotContainsString('\\Phunkie\\Effect\\Functions\\io\\io(', $source);
    }

    public function test_a_failing_migration_leaves_nothing_behind()
    {
        $this->writeMigration(
            '2026_01_01_000003_CreateBolts',
            'CreateBolts',
            'CREATE TABLE bolts (id INTEGER PRIMARY KEY)"); throw new \\RuntimeException("boom',
            'DROP TABLE bolts'
        );

        try {
            ob_start();
            $this->migrator->run()->run($this->conn)->unsafeRun();
            $this->fail('The migration should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        } finally {
            ob_end_clean();
        }

        $this->assertSame(['migrations'], $this->tables());
        $this->assertSame([], $this->logged());
    }

    private function writeMigration(string $fileName, string $className, string $up, string $down): void
    {
        file_put_contents($this->path . '/' . $fileName . '.php', sprintf(<<<'PHP'
            <?php

            use Phunkie\Phetch\Migration\Migration;
            use Phunkie\Phetch\Query;

            use function Phunkie\Effect\Functions\io\io;

            class %s implements Migration
            {
                public function up(): Query
                {
                    return new Query(fn($conn) => io(function () use ($conn) { $conn->pdo()->exec("%s"); }));
                }

                public function down(): Query
                {
                    return new Query(fn($conn) => io(function () use ($conn) { $conn->pdo()->exec("%s"); }));
                }
            }
            PHP, $className, $up, $down));
    }

    private function tables(): array
    {
        return $this->conn->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function logged(): array
    {
        return $this->conn->pdo()->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    }
}
