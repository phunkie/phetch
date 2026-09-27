<?php

namespace Phunkie\Phetch\Functions;

use Phunkie\Effect\IO\IO;
use Phunkie\Phetch\Connection\Connection;
use PDO;

use function Phunkie\Effect\Functions\io\io;

/**
 * Describe opening a database connection: the PDO options are passed as they are, the statements run
 * once the connection is open, in order. Returns IO<Connection>.
 *
 * @param list<string> $statements
 */
function connect(string $dsn, ?string $username = null, ?string $password = null, array $options = [], array $statements = []): IO
{
    return io(fn() => new Connection(new PDO($dsn, $username, $password, $options), $statements));
}
