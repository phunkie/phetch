<?php

namespace Phunkie\Phetch\Connection;

use PDO;
use Phunkie\Phetch\Identifier;

class Connection
{
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
}
