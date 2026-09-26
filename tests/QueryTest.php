<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Phunkie\Phetch\Attributes\Column;
use Phunkie\Phetch\Attributes\Generated;
use Phunkie\Phetch\Attributes\Table;
use Phunkie\Phetch\Connection\Connection;

use DateTimeImmutable;
use InvalidArgumentException;
use Phunkie\Phetch\ConstraintViolation;
use Phunkie\Phetch\Query;
use Phunkie\Types\Option;
use RuntimeException;

use function Phunkie\Effect\Functions\io\io;
use function Phunkie\Phetch\Functions\{connect, find, findBy, create, insert, update, where, all, remove, transaction};

#[Table('users')]
readonly class User {
    public function __construct(
        public int $id,
        public string $name,
        public string $email
    ) {}
}

#[Table('teams')]
readonly class Team {
    public function __construct(
        public int $id,
        public string $name,
        public string $group
    ) {}
}

#[Table('books')]
readonly class Book {
    public function __construct(
        public int $id,
        #[Column('author_id')]
        public int $writer,
        public string $title,
        public int $publishedYear
    ) {}
}

#[Table('accounts', primaryKey: 'account_id')]
readonly class Account {
    public function __construct(
        public int $account_id,
        public string $name
    ) {}
}

enum Country: string {
    case GB = 'GB';
    case PT = 'PT';
}

final readonly class Email {
    public function __construct(public string $value) {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('must be an email address');
        }
    }

    public function __toString(): string {
        return $this->value;
    }
}

#[Table('members')]
readonly class Member {
    public function __construct(
        #[Generated]
        public int $id,
        public string $name,
        public Email $email,
        public Country $country,
        public DateTimeImmutable $joinedOn,
        public ?string $nickname = null
    ) {}
}

#[Table('memberships')]
readonly class Membership {
    public function __construct(
        public int $member_id,
        public int $group_id
    ) {}
}

#[Table('users; DROP TABLE users')]
readonly class Hostile {
    public function __construct(public int $id) {}
}

class QueryTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
            $this->markTestSkipped('SQLite PDO driver not available');
        }

        $this->conn = connect('sqlite::memory:')->unsafeRun();
        
        $pdo = $this->conn->pdo();
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)");
        $pdo->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT, "group" TEXT)');
        $pdo->exec('CREATE TABLE books (id INTEGER PRIMARY KEY, author_id INTEGER, title TEXT, published_year INTEGER)');
        $pdo->exec('CREATE TABLE accounts (account_id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, country TEXT NOT NULL, joined_on TEXT NOT NULL, nickname TEXT)');
        $pdo->exec('CREATE TABLE memberships (member_id INTEGER NOT NULL, group_id INTEGER NOT NULL, PRIMARY KEY (member_id, group_id))');
    }

    public function test_value_objects_enums_and_dates_round_trip_as_scalar_columns()
    {
        $member = create(Member::class, [
            'name' => 'Ada',
            'email' => new Email('ada@example.com'),
            'country' => Country::GB,
            'joinedOn' => new DateTimeImmutable('2020-01-02 03:04:05'),
        ])->run($this->conn)->unsafeRun();

        $this->assertEquals(new Email('ada@example.com'), $member->email);
        $this->assertSame(Country::GB, $member->country);
        $this->assertEquals(new DateTimeImmutable('2020-01-02 03:04:05'), $member->joinedOn);
        $this->assertNull($member->nickname);

        $row = $this->conn->pdo()->query('SELECT email, country, joined_on FROM members')->fetch();
        $this->assertSame(['email' => 'ada@example.com', 'country' => 'GB', 'joined_on' => '2020-01-02 03:04:05'], $row);
        $this->assertEquals($member, findBy(Member::class, 'country', Country::GB)->run($this->conn)->unsafeRun()->get());
    }

    public function test_a_constraint_violation_is_reported_as_such()
    {
        $data = ['name' => 'Ada', 'email' => new Email('ada@example.com'), 'country' => Country::GB, 'joinedOn' => new DateTimeImmutable('2020-01-02')];
        create(Member::class, $data)->run($this->conn)->unsafeRun();

        $this->expectException(ConstraintViolation::class);

        create(Member::class, $data)->run($this->conn)->unsafeRun();
    }

    public function test_insert_writes_rows_that_have_no_generated_key()
    {
        $rows = insert(Membership::class, ['member_id' => 1, 'group_id' => 2])->run($this->conn)->unsafeRun();

        $this->assertSame(1, $rows);
        $this->assertSame(1, where(Membership::class, 'member_id', 1)->count()->run($this->conn)->unsafeRun());
    }

    public function test_where_in_and_delete_on_the_builder()
    {
        foreach ([[1, 2], [1, 3], [2, 2]] as [$member, $group]) {
            insert(Membership::class, ['member_id' => $member, 'group_id' => $group])->run($this->conn)->unsafeRun();
        }

        $this->assertSame(2, where(Membership::class, 'member_id', 1)->whereIn('group_id', [2, 3])->count()->run($this->conn)->unsafeRun());
        $this->assertSame(2, where(Membership::class, 'member_id', 1)->delete()->run($this->conn)->unsafeRun());
        $this->assertSame(1, all(Membership::class)->count()->run($this->conn)->unsafeRun());
    }

    public function test_a_transaction_rolls_back_everything_when_a_step_fails()
    {
        $work = create(User::class, ['name' => 'A', 'email' => 'a@a.com'])
            ->flatMap(fn() => Query::liftIO(io(fn() => throw new RuntimeException('boom'))));

        try {
            transaction($work)->run($this->conn)->unsafeRun();
            $this->fail('The transaction should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, all(User::class)->count()->run($this->conn)->unsafeRun());
        $this->assertSame('B', transaction(create(User::class, ['name' => 'B', 'email' => 'b@b.com']))->run($this->conn)->unsafeRun()->name);
    }

    public function test_columns_map_to_parameters_by_attribute_or_by_snake_case()
    {
        $book = create(Book::class, ['author_id' => 7, 'title' => 'Notes', 'published_year' => 1843])
            ->run($this->conn)
            ->unsafeRun();

        $this->assertSame(7, $book->writer);
        $this->assertSame(1843, $book->publishedYear);
        $this->assertEquals($book, find(Book::class, $book->id)->run($this->conn)->unsafeRun()->get());
    }

    public function test_create_and_update_accept_constructor_parameter_names_as_keys()
    {
        $book = create(Book::class, ['writer' => 7, 'title' => 'Notes', 'publishedYear' => 1843])
            ->run($this->conn)
            ->unsafeRun();

        $this->assertSame(7, $book->writer);
        $this->assertSame(1843, $book->publishedYear);

        $updated = update(Book::class, $book->id, ['writer' => 9, 'published_year' => 1844])->run($this->conn)->unsafeRun()->get();

        $this->assertSame(9, $updated->writer);
        $this->assertSame(1844, $updated->publishedYear);
    }

    public function test_the_primary_key_column_is_configurable()
    {
        $account = create(Account::class, ['name' => 'Ada'])->run($this->conn)->unsafeRun();

        $this->assertSame(1, $account->account_id);
        $this->assertEquals('Ada', find(Account::class, 1)->run($this->conn)->unsafeRun()->get()->name);
        $this->assertEquals('Grace', update(Account::class, 1, ['name' => 'Grace'])->run($this->conn)->unsafeRun()->get()->name);
        $this->assertTrue(remove(Account::class, 1)->run($this->conn)->unsafeRun());
        $this->assertTrue(find(Account::class, 1)->run($this->conn)->unsafeRun()->isEmpty());
    }

    public function test_reserved_words_work_as_column_names_everywhere()
    {
        $team = create(Team::class, ['name' => 'Core', 'group' => 'admins'])->run($this->conn)->unsafeRun();
        $this->assertEquals('admins', $team->group);

        $updated = update(Team::class, $team->id, ['group' => 'ops'])->run($this->conn)->unsafeRun()->get();
        $this->assertEquals('ops', $updated->group);

        $this->assertEquals('Core', findBy(Team::class, 'group', 'ops')->run($this->conn)->unsafeRun()->get()->name);
        $this->assertEquals(1, where(Team::class, 'group', 'ops')->orderBy('group')->get()->run($this->conn)->unsafeRun()->length);
    }

    public function test_count_first_and_offset_page_through_results()
    {
        foreach (['A', 'B', 'C'] as $name) {
            create(User::class, ['name' => $name, 'email' => strtolower($name) . '@a.com'])->run($this->conn)->unsafeRun();
        }

        $this->assertSame(3, all(User::class)->count()->run($this->conn)->unsafeRun());
        $this->assertSame(2, where(User::class, 'name', '!=', 'A')->count()->run($this->conn)->unsafeRun());
        $this->assertEquals('B', where(User::class, 'name', '!=', 'A')->orderBy('name')->first()->run($this->conn)->unsafeRun()->get()->name);
        $this->assertTrue(where(User::class, 'name', 'Z')->first()->run($this->conn)->unsafeRun()->isEmpty());

        $page = all(User::class)->orderBy('name')->limit(1)->offset(2)->get()->run($this->conn)->unsafeRun();
        $this->assertEquals(['C'], $page->map(fn(User $user) => $user->name)->toArray());
    }

    public function test_update_with_no_data_is_rejected_before_touching_the_database()
    {
        $this->expectException(InvalidArgumentException::class);

        update(User::class, 1, []);
    }

    public function test_table_names_that_are_not_identifiers_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        find(Hostile::class, 1);
    }

    public function test_column_names_that_are_not_identifiers_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        create(User::class, ['name) VALUES (1); DROP TABLE users; --' => 'x']);
    }

    public function test_find_returns_none_when_not_found()
    {
        $result = find(User::class, 999)->run($this->conn)->unsafeRun();
        $this->assertTrue($result->isEmpty());
    }

    public function test_create_and_find()
    {
        $user = create(User::class, ['name' => 'John', 'email' => 'john@test.com'])
            ->run($this->conn)
            ->unsafeRun();
        
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals(1, $user->id);
        $this->assertEquals('John', $user->name);
        
        // Find
        $opt = find(User::class, 1)->run($this->conn)->unsafeRun();
        $this->assertTrue($opt->isDefined());
        $this->assertEquals($user, $opt->get());
    }

    public function test_where_query()
    {
        // Compose create operations
        create(User::class, ['name' => 'A', 'email' => 'a@a.com'])
            ->flatMap(fn($_) => create(User::class, ['name' => 'B', 'email' => 'b@b.com']))
            ->run($this->conn)
            ->unsafeRun();
        
        // Where
        $list = where(User::class, 'name', 'A')->get()->run($this->conn)->unsafeRun();
        $this->assertEquals(1, $list->length);
        $this->assertEquals('A', $list->head->name);
        
        // All
        $all = all(User::class)->run($this->conn)->unsafeRun();
        $this->assertEquals(2, $all->length);
    }
    
    public function test_stream_yields_hydrated_models()
    {
        create(User::class, ['name' => 'A', 'email' => 'a@a.com'])
            ->flatMap(fn($_) => create(User::class, ['name' => 'B', 'email' => 'b@b.com']))
            ->run($this->conn)
            ->unsafeRun();

        $names = where(User::class, 'name', 'B')
            ->stream()
            ->run($this->conn)
            ->unsafeRun()
            ->map(fn(User $user) => $user->name)
            ->compile()
            ->toList();

        $this->assertEquals(ImmList('B'), $names);
    }

    public function test_remove_reports_whether_a_row_was_deleted()
    {
        create(User::class, ['name' => 'A', 'email' => 'a@a.com'])->run($this->conn)->unsafeRun();

        $this->assertTrue(remove(User::class, 1)->run($this->conn)->unsafeRun());
        $this->assertTrue(find(User::class, 1)->run($this->conn)->unsafeRun()->isEmpty());
        $this->assertFalse(remove(User::class, 1)->run($this->conn)->unsafeRun());
    }

    public function test_pure_lifts_a_value_into_a_query()
    {
        $this->assertSame(42, Query::pure(42)->run($this->conn)->unsafeRun());
    }

    public function test_lift_io_embeds_an_effect_into_a_query()
    {
        $calls = 0;
        $query = Query::liftIO(io(function () use (&$calls) {
            return ++$calls;
        }));

        $this->assertSame(0, $calls);
        $this->assertSame(1, $query->run($this->conn)->unsafeRun());
    }

    public function test_flat_map_can_branch_into_a_pure_query()
    {
        $greeting = find(User::class, 1)->flatMap(fn(Option $user) => $user->isDefined()
            ? Query::pure('Hello ' . $user->get()->name)
            : Query::pure('Hello stranger'));

        $this->assertSame('Hello stranger', $greeting->run($this->conn)->unsafeRun());

        create(User::class, ['name' => 'Ada', 'email' => 'ada@a.com'])->run($this->conn)->unsafeRun();

        $this->assertSame('Hello Ada', $greeting->run($this->conn)->unsafeRun());
    }

    public function test_hydration_handles_order()
    {
        $pdo = $this->conn->pdo();
        $pdo->exec("INSERT INTO users (email, name) VALUES ('c@c.com', 'C')");

        $opt = find(User::class, 1)->run($this->conn)->unsafeRun();
        $this->assertTrue($opt->isDefined());
        $user = $opt->get();
        $this->assertEquals('C', $user->name);
        $this->assertEquals('c@c.com', $user->email);
    }
}
