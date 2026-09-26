<?php

namespace Phunkie\Phetch\Migration;

use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Query;
use function Phunkie\Effect\Functions\io\io;

class Migrator
{
    public function __construct(private string $path) {}

    public function init(): Query
    {
        return new Query(function(Connection $conn) {
            return io(function() use ($conn) {
                $driver = $conn->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
                $pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
                if ($driver === 'pgsql') $pk = 'SERIAL PRIMARY KEY';

                $sql = "CREATE TABLE IF NOT EXISTS migrations (
                    id $pk,
                    migration VARCHAR(255),
                    batch INTEGER
                )";
                $conn->pdo()->exec($sql);
            });
        });
    }

    public function run(): Query
    {
        return new Query(function(Connection $conn) {
            return io(function() use ($conn) {
                $this->init()->run($conn)->unsafeRun();

                $executed = $this->getExecuted($conn);
                $files = glob($this->path . '/*.php');
                sort($files);

                $batch = $this->getNextBatch($conn);
                $count = 0;

                foreach ($files as $file) {
                    $name = basename($file, '.php');
                    if (in_array($name, $executed)) continue;

                    echo "Migrating: $name\n";
                    require_once $file;

                    $className = $this->classNameOf($name);
                    $migration = new $className();
                    if ($migration instanceof Migration) {
                        $migration->up()->run($conn)->unsafeRun();
                        $this->log($conn, $name, $batch);
                        echo "Migrated:  $name\n";
                        $count++;
                    }
                }
                
                if ($count === 0) echo "Nothing to migrate.\n";
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
                $migrations = $stmt->fetchAll(\PDO::FETCH_COLUMN);

                foreach ($migrations as $name) {
                    echo "Rolling back: $name\n";
                    $file = $this->path . '/' . $name . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                        $className = $this->classNameOf($name);
                        $migration = new $className();
                        if ($migration instanceof Migration) {
                            $migration->down()->run($conn)->unsafeRun();
                            $this->removeLog($conn, $name);
                            echo "Rolled back:  $name\n";
                        }
                    } else {
                        echo "Warning: Migration file $file not found.\n";
                    }
                }
            });
        });
    }
    
    public function make(string $name): IO
    {
        return io(function() use ($name) {
            $timestamp = date('Y_m_d_His');
            $fileName = "{$timestamp}_{$name}.php";
            $className = $name;
            
            $content = "<?php\n\nuse Phunkie\Phetch\Migration\Migration;\nuse Phunkie\Phetch\Query;\n\nclass $className implements Migration\n{\n    public function up(): Query\n    {\n        return new Query(fn(\$conn) => \Phunkie\Effect\Functions\io\io(fn() => \n            \$conn->pdo()->exec(\"CREATE TABLE ...\")\n        ));\n    }\n\n    public function down(): Query\n    {\n        return new Query(fn(\$conn) => \Phunkie\Effect\Functions\io\io(fn() => \n            \$conn->pdo()->exec(\"DROP TABLE ...\")\n        ));\n    }\n}\n";
            
            file_put_contents($this->path . '/' . $fileName, $content);
            echo "Created Migration: $fileName\n";
        });
    }

    private function classNameOf(string $migrationName): string
    {
        return preg_replace('/^(\d+_)+/', '', $migrationName);
    }

    private function getExecuted(Connection $conn): array
    {
        $stmt = $conn->pdo()->query("SELECT migration FROM migrations");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
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
