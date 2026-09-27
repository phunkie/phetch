<?php

namespace Tests\Unit;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Constraint;

class ConstraintTest extends TestCase
{
    #[DataProvider('driverReports')]
    public function test_the_constraint_is_read_from_what_the_driver_reports(array $errorInfo, Constraint $expected)
    {
        $exception = new PDOException('Integrity constraint violation');
        $exception->errorInfo = $errorInfo;

        $this->assertSame($expected, Constraint::reportedBy($exception));
    }

    public static function driverReports(): iterable
    {
        yield 'sqlite unique' => [['23000', 19, 'UNIQUE constraint failed: members.email'], Constraint::Unique];
        yield 'sqlite foreign key' => [['23000', 19, 'FOREIGN KEY constraint failed'], Constraint::ForeignKey];
        yield 'sqlite not null' => [['23000', 19, 'NOT NULL constraint failed: members.name'], Constraint::NotNull];
        yield 'sqlite check' => [['23000', 19, 'CHECK constraint failed: rating'], Constraint::Check];
        yield 'mysql duplicate entry' => [['23000', 1062, "Duplicate entry 'ada@example.com' for key 'members.email'"], Constraint::Unique];
        yield 'mysql child row' => [['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'], Constraint::ForeignKey];
        yield 'mysql parent row' => [['23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails'], Constraint::ForeignKey];
        yield 'mysql not null' => [['23000', 1048, "Column 'name' cannot be null"], Constraint::NotNull];
        yield 'mysql check' => [['23000', 3819, "Check constraint 'rating' is violated."], Constraint::Check];
        yield 'postgres unique' => [['23505', 7, 'ERROR:  duplicate key value violates unique constraint "members_email_key"'], Constraint::Unique];
        yield 'postgres foreign key' => [['23503', 7, 'ERROR:  insert or update on table "books" violates foreign key constraint'], Constraint::ForeignKey];
        yield 'postgres not null' => [['23502', 7, 'ERROR:  null value in column "name" violates not-null constraint'], Constraint::NotNull];
        yield 'postgres check' => [['23514', 7, 'ERROR:  new row for relation "members" violates check constraint'], Constraint::Check];
        yield 'anything else in class 23' => [['23000', 0, 'Integrity constraint violation'], Constraint::Other];
        yield 'no driver detail' => [[], Constraint::Other];
    }
}
