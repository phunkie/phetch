<?php

namespace Phunkie\Phetch\Connection;

use PDO;
use PDOStatement;
use Phunkie\Phetch\Identifier;

class Connection
{
    /**
     * PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, which PDO defines only when pdo_mysql is loaded.
     */
    private const MYSQL_ATTR_USE_BUFFERED_QUERY = 1000;

    private string $driver;

    /**
     * @param list<string> $statements SQL run once the connection is open, in order
     */
    public function __construct(private PDO $pdo, array $statements = [])
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function quote(Identifier $identifier): string
    {
        return $identifier->quotedWith('mysql' === $this->driver ? '`' : '"');
    }

    /**
     * Prepare and run a statement.
     *
     * @param list<mixed> $params
     */
    public function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Prepare and run a statement whose rows will be fetched one at a time, however many there are.
     * MySQL is told not to buffer the result set client-side while the statement runs, and no other
     * statement can run on the connection until the rows have all been fetched; PostgreSQL reads
     * through a server-side cursor; SQLite is incremental already.
     *
     * @param list<mixed> $params
     */
    public function stream(string $sql, array $params): PDOStatement
    {
        return match ($this->driver) {
            'mysql' => $this->unbuffered(fn () => $this->run($sql, $params)),
            'pgsql' => $this->throughCursor($sql, $params),
            default => $this->run($sql, $params),
        };
    }

    private function unbuffered(callable $run): PDOStatement
    {
        $buffered = $this->pdo->getAttribute(self::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->setAttribute(self::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        try {
            return $run();
        } finally {
            $this->pdo->setAttribute(self::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function throughCursor(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL]);
        $stmt->execute($params);

        return $stmt;
    }
}
