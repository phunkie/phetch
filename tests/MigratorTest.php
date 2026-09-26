<?php

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Migration\Migrator;

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
