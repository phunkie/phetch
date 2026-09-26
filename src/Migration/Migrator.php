<?php

namespace Phunkie\Phetch\Migration;

use PDO;
use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Query;
use Phunkie\Types\ImmList;
use Throwable;

use function Phunkie\Effect\Functions\io\io;

class Migrator
{
    public function __construct(private string $path) {}

    public function init(): Query
    {
        return new Query(function(Connection $conn) {
            return io(function() use ($conn) {
                $driver = $conn->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
                $pk = match ($driver) {
                    'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                    'pgsql' => 'SERIAL PRIMARY KEY',
                    default => 'INT AUTO_INCREMENT PRIMARY KEY',
                };

                $conn->pdo()->exec("CREATE TABLE IF NOT EXISTS migrations (
                    id $pk,
                    migration VARCHAR(255),
                    batch INTEGER
                )");
            });
        });
    }

    public function run(): Query
    {
        return new Query(function(Connection $conn) {
            return io(function() use ($conn) {
                $this->init()->run($conn)->unsafeRun();

                $executed = array_keys($this->executedBatches($conn));
                $batch = $this->getNextBatch($conn);
                $count = 0;

                foreach ($this->files() as $name => $file) {
                    if (in_array($name, $executed, true)) {
                        continue;
                    }

                    echo "Migrating: $name\n";
                    $migration = $this->load($file, $name);
                    $this->transactionally($conn, function () use ($conn, $migration, $name, $batch) {
                        $migration->up()->run($conn)->unsafeRun();
                        $this->log($conn, $name, $batch);
                    });
                    echo "Migrated:  $name\n";
                    $count++;
                }

                if ($count === 0) {
                    echo "Nothing to migrate.\n";
                }
            });
        });
    }

    public function rollback(): Query
    {
        return new Query(function(Connection $conn) {
            return io(function() use ($conn) {
                $batch = $this->getLastBatch($conn);
                if ($batch === 0) {
                    echo "Nothing to rollback.\n";

                    return;
                }

                $stmt = $conn->pdo()->prepare("SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC");
                $stmt->execute([$batch]);

                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                    echo "Rolling back: $name\n";
                    $file = $this->path . '/' . $name . '.php';
                    if (!file_exists($file)) {
                        echo "Warning: Migration file $file not found.\n";

                        continue;
                    }

                    $migration = $this->load($file, $name);
                    $this->transactionally($conn, function () use ($conn, $migration, $name) {
                        $migration->down()->run($conn)->unsafeRun();
                        $this->removeLog($conn, $name);
                    });
                    echo "Rolled back:  $name\n";
                }
            });
        });
    }

    /**
     * @return Query<ImmList<MigrationStatus>>
     */
    public function status(): Query
    {
        return new Query(function(Connection $conn) {
            return io(function() use ($conn) {
                $this->init()->run($conn)->unsafeRun();
                $batches = $this->executedBatches($conn);

                return ImmList(...array_map(
                    fn(string $name) => new MigrationStatus($name, $batches[$name] ?? null),
                    array_keys($this->files())
                ));
            });
        });
    }

    public function make(string $name): IO
    {
        return io(function() use ($name) {
            $fileName = date('Y_m_d_His') . "_{$name}.php";
            $content = <<<PHP
                <?php

                use Phunkie\\Phetch\\Migration\\Migration;
                use Phunkie\\Phetch\\Query;

                use function Phunkie\\Effect\\Functions\\io\\io;

                class {$name} implements Migration
                {
                    public function up(): Query
                    {
                        return new Query(fn(\$conn) => io(fn() => \$conn->pdo()->exec("CREATE TABLE ...")));
                    }

                    public function down(): Query
                    {
                        return new Query(fn(\$conn) => io(fn() => \$conn->pdo()->exec("DROP TABLE ...")));
                    }
                }

                PHP;

            file_put_contents($this->path . '/' . $fileName, $content);
            echo "Created Migration: $fileName\n";
        });
    }

    /**
     * @return array<string, string> migration name => file path, in run order
     */
    private function files(): array
    {
        $files = glob($this->path . '/*.php');
        sort($files);

        $byName = [];
        foreach ($files as $file) {
            $byName[basename($file, '.php')] = $file;
        }

        return $byName;
    }

    private function load(string $file, string $name): Migration
    {
        require_once $file;
        $className = $this->classNameOf($name);
        $migration = new $className();

        if (!$migration instanceof Migration) {
            throw new \RuntimeException(sprintf('"%s" does not implement %s.', $className, Migration::class));
        }

        return $migration;
    }

    private function classNameOf(string $migrationName): string
    {
        return preg_replace('/^(\d+_)+/', '', $migrationName);
    }

    private function transactionally(Connection $conn, callable $work): void
    {
        $pdo = $conn->pdo();
        $pdo->beginTransaction();

        try {
            $work();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @return array<string, int> migration name => batch
     */
    private function executedBatches(Connection $conn): array
    {
        return $conn->pdo()->query("SELECT migration, batch FROM migrations")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function getNextBatch(Connection $conn): int
    {
        return $this->getLastBatch($conn) + 1;
    }

    private function getLastBatch(Connection $conn): int
    {
        $stmt = $conn->pdo()->query("SELECT MAX(batch) FROM migrations");

        return (int) $stmt->fetchColumn() ?: 0;
    }

    private function log(Connection $conn, string $name, int $batch): void
    {
        $stmt = $conn->pdo()->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$name, $batch]);
    }

    private function removeLog(Connection $conn, string $name): void
    {
        $stmt = $conn->pdo()->prepare("DELETE FROM migrations WHERE migration = ?");
        $stmt->execute([$name]);
    }
}
