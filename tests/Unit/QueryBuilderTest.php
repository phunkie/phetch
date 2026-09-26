<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Connection\Connection;
use Phunkie\Phetch\Query\QueryBuilder;
use PDO;
use PDOStatement;

class QueryBuilderTest extends TestCase
{
    public function test_get_generates_correct_sql()
    {
        $mockPdo = $this->createMock(PDO::class);
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockConn = new Connection($mockPdo);
        
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with("SELECT * FROM users WHERE age > ? ORDER BY name ASC LIMIT 10")
            ->willReturn($mockStmt);
            
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with([18]);
            
        $mockStmt->method('fetchAll')->willReturn([]);

        $qb = new QueryBuilder('User', 'users');
        $qb->where('age', '>', 18)
           ->orderBy('name')
           ->limit(10)
           ->get()
           ->run($mockConn)
           ->unsafeRun();
    }
}
