<?php

namespace Phunkie\Phetch\Functions;

use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use PDO;

use function Phunkie\Effect\Functions\io\io;

/**
 * Create a database connection.
 * returns IO<Connection>
 */
function connect(string $dsn, ?string $username = null, ?string $password = null, array $options = []): IO
{
    return io(fn() => new Connection(new PDO($dsn, $username, $password, $options)));
}
