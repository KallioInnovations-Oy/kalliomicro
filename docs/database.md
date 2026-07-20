# Database Layer

> Sources: `src/Database/Connection.php`, `src/Database/QueryBuilder.php`.
> `src/Database/` is standalone — it imports no other framework module. There is **no model/ORM layer** (rows are associative arrays) and **no migration system** — schema is managed outside the framework.

---

## Connection

```php
public function __construct(array $config)
```

Config keys (merged over defaults): `driver` (`mysql`), `host` (`localhost`), `port` (`3306`), `database`, `username`, `password`, `charset` (`utf8mb4`), `collation` (`utf8mb4_unicode_ci`), `prefix` (`''`, stored but never auto-applied), `options` (`[]`).

The PDO connection is **lazy** — nothing connects until the first query. PDO is configured with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, **`EMULATE_PREPARES = false`** (native prepares — a named placeholder may appear only once per statement), `STRINGIFY_FETCHES = false`. For MySQL, `SET NAMES '<charset>' COLLATE '<collation>'` runs on connect. DSN drivers: `mysql`, `pgsql`, `sqlite`; anything else throws. Connection failures re-throw as `RuntimeException`.

**`options` are merged by key, and yours win.** PDO attributes are integer constants, so the merge uses `array_replace()` — `array_merge()` renumbers integer keys, which silently moved every default onto an unrelated attribute (see the 1.2.4 entry in the [CHANGELOG](../CHANGELOG.md)). Anything you set under its real constant reaches PDO and overrides the default:

```php
// config/database.php — matched-row semantics instead of changed-row
'options' => [PDO::MYSQL_ATTR_FOUND_ROWS => true],
```

### Raw query API

```php
public function query(string $sql, array $bindings = []): PDOStatement
public function select(string $sql, array $bindings = []): array        // list of assoc rows
public function selectOne(string $sql, array $bindings = []): ?array
public function selectValue(string $sql, array $bindings = []): mixed
```

Integer binding keys bind positionally, string keys as `:named`; PDO types are inferred (int/bool/null/string).

### Write helpers

```php
public function insert(string $table, array $data): int                            // last insert id
public function upsert(string $table, array $data, array $updateColumns = []): int // MySQL ON DUPLICATE KEY UPDATE
public function update(string $table, array $data, array $where): int              // CHANGED rows — see below
public function delete(string $table, array $where): int
```

- `upsert()` with empty `$updateColumns` updates **all** data columns on key collision (MySQL-specific).
- `update()`/`delete()` throw `RuntimeException` on an empty `$where` array — a deliberate whole-table write goes through `query()` with explicit SQL. (The QueryBuilder's `update()`/`delete()` have the same guard.)
- ⚠ **`update()`, `delete()` and `affectedRows()` return rows *changed*, not rows *matched*** (as of 2026-07-18). `MYSQL_ATTR_FOUND_ROWS` is not set, so rewriting a row with identical values returns 0 — indistinguishable from "no such row". Do not use the return value as an existence check; `SELECT` first when you need to tell those apart. A deployment that prefers matched-row semantics sets the attribute itself via `config('database.connections.*.options')` (see "options are merged by key" above) — the base does not set it because flipping it would silently change the meaning of every existing downstream's return value. Note this escape hatch **did not work before 1.2.4**: the options merge renumbered the attribute key, so the setting never reached PDO.
- ⚠ **`upsert()` returns 0 whenever the update path is taken** (as of 2026-07-18), because MySQL's `lastInsertId()` reports nothing for `ON DUPLICATE KEY UPDATE`. A 0 means "updated an existing row" *or* "failed" — check `affectedRows()` (1 = inserted, 2 = updated with a change, 0 = updated with no change) if you need to distinguish.
- **Bindings must be scalar or null.** An array or object binding throws `InvalidArgumentException`; it previously reached PDO and stringified to the literal `'Array'`, writing that into the column. A list of values is `whereIn()`'s job.
- ⚠ **`insert()` returns 0 on a table with no AUTO_INCREMENT** (as of 2026-07-18), because that is what `lastInsertId()` reports — indistinguishable from failure. A failed insert throws, so treat "no exception" as success and ignore the return value on such tables. Same shape as the `upsert()` note above.
- **`affectedRows()` reports the most recent statement, including a failed one** (it returns 0 there). It used to keep reporting the *previous* statement's count after a failure, which read as a successful write.

### Transactions

```php
public function beginTransaction(): bool
public function commit(): bool
public function rollback(): bool
public function transaction(callable $callback): mixed   // begin → callback → commit; rollback + rethrow on Throwable
```

⚠ **Transactions do not nest.** `transaction()` inside an open transaction hits PDO's "already an active transaction" error — there is no savepoint support. Keep transaction scope at the outermost service level.

**Contract: `transaction()` dispatches through `$this->beginTransaction()` / `commit()` / `rollback()`,** never through the PDO handle directly. That indirection is what makes a savepoint-nesting `Connection` subclass possible — a named construction seam in [conventions.md](conventions.md) — so "optimising" it into `$this->getPdo()->beginTransaction()` would silently break every such subclass. It is contract, not an implementation detail.

A commit that fails leaves no active transaction, so the subsequent `rollback()` raises its own "no active transaction". That secondary failure used to **replace** the real cause; the original is now preserved as the `previous` exception and both messages are reported.

### Misc

```php
public function table(string $table): QueryBuilder
public function lastInsertId(): int
public function affectedRows(): int
public function quoteIdentifier(string $identifier): string
public function disconnect(): void
public function isConnected(): bool
public function getPrefix(): string
public function getDatabaseName(): string
public function getPdo(): PDO
```

`quoteIdentifier()` backtick-quotes identifiers, understands `table.column` dotting, and passes `*` / `table.*` through unquoted. It **fails closed**: an identifier containing a backtick or a space throws rather than passing through — that passthrough was an alias escape hatch, and it made the method an injection route for exactly the inputs that matter. Aliases and expressions belong in a `RawExpression`. Acceptance is still governed by the QueryBuilder's stricter `validateIdentifier()`; this method only quotes, and neither is a substitute for the other.

### Server expectations

The base does **not** assert server-side settings — these are deployment policy, the same line drawn for the scheduler lock and the session store. Two are worth setting deliberately:

- **`sql_mode`** — a non-strict server silently truncates oversize values instead of erroring. Run with `STRICT_TRANS_TABLES` at minimum.
- **Session time zone** — `NOW()` follows the server's zone while application code writes GMT; the two were measured 180 minutes apart on a development machine here. Prefer application-generated timestamps, or set the connection time zone explicitly.

Both can be applied per connection through `config('database.connections.*.options')`, which is merged into PDO's options — for example an `PDO::MYSQL_ATTR_INIT_COMMAND` setting `sql_mode` and `time_zone`.

---

## QueryBuilder

Obtained via `Connection::table()`, `$this->table('name')` in controllers, or the `db('name')` helper. All values reach SQL through auto-generated named bindings (`:p0`, `:p1`, …) — **never interpolate values into SQL**.

```php
$rows = $this->table('core_users')
    ->select(['id', 'username', 'email'])
    ->where('active', 1)
    ->orderByDesc('id')
    ->forPage($page, 25)
    ->get();
```

### Identifier validation — the no-alias rule

Every column the builder accepts — `select()`, `addSelect()`, join arguments, aggregates, `value()`, `pluck()`, `increment()`/`decrement()`, and the whole `where` / `orWhere` / `whereIn` / `whereNotIn` / `whereNull` / `whereNotNull` / `whereBetween` / `groupBy` / `orderBy` family — is checked against:

```
^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*|\.\*)?$
```

Allowed: `column`, `table.column`, `table.*`, bare `*`. **Rejected** (throws `InvalidArgumentException`): `AS` aliases, spaces, function calls, three-part names, hyphens. `RawExpression` instances pass through verbatim. Consequences: the builder cannot express aliases — self-joins and aliased selects use raw SQL via `Connection::select()` with explicit bindings.

Comparison operators are interpolated into the compiled SQL, so they are allowlisted too — `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, case-insensitively — in **both** `where()` and the JOIN methods. Anything else throws `InvalidArgumentException`.

`Connection::quoteIdentifier()` fails closed: an identifier containing a backtick or a space raises rather than passing through unquoted. It is a quoting mechanism, not a validator — the shape check above is the actual defence, and both run.

Never pass user input as a column name regardless — whitelist sortable columns in application code. The validator makes `?sort=<column>` non-injectable, not correct.

### Column selection & joins

```php
public function select(string|array $columns = ['*']): self   // validates each column; replaces the list
public function addSelect(string ...$columns): self
public function distinct(): self
public function join(string $table, string $first, string $operator, string $second): self   // INNER
public function leftJoin(...): self
public function rightJoin(...): self
```

### WHERE clauses

```php
public function where(string|Closure $column, mixed $operatorOrValue = null, mixed $value = null): self
public function orWhere(string|Closure $column, mixed $operatorOrValue = null, mixed $value = null): self
// a Closure opens a parenthesised group — see "Grouping" below
public function whereIn(string $column, array $values): self
public function whereNotIn(string $column, array $values): self
public function whereNull(string $column): self
public function whereNotNull(string $column): self
public function whereBetween(string $column, mixed $min, mixed $max): self
public function whereLike(string $column, string $value): self
public function whereRaw(string $sql, array $bindings = []): self
```

- Two-argument shorthand: `where('active', 1)` means `active = 1`; `where('deleted_at', null)` compiles **`IS NULL`**.
- Explicit null comparisons are normalized: `where('col', '=', null)` → `IS NULL`, `'!='`/`'<>'` → `IS NOT NULL`; any other operator with a null value throws (SQL `= NULL` never matches — the builder refuses to emit it).
- `whereIn([])` makes the **whole** query match nothing; `whereNotIn([])` compiles `1 = 1` (matches everything) — no invalid `IN ()` SQL. The empty-`IN` case collapses the entire WHERE clause to `0 = 1` rather than appending it, because AND binds tighter than OR: appended, `a = 1 OR b = 2 AND 0 = 1` reduces to `a = 1` and an empty permission allowlist would fail **open**.
- `whereRaw()` requires the `?` placeholder count to equal `count($bindings)` (throws otherwise) and rewrites them into named bindings. The fragment text is the caller's responsibility; values always go through `$bindings`.
- Chaining: only `orWhere()` produces `OR` — every other variant is `AND`; there is no grouped-parenthesis support (use `whereRaw()` for `(a OR b)` groups).

### Grouping — mixed AND/OR chains

Conditions compile as a **flat list**, and SQL binds `AND` tighter than `OR`. So a mixed chain does not mean what it reads like:

```php
->where('a', 1)->orWhere('b', 2)->where('c', 3)
// WHERE `a` = :p0 OR `b` = :p1 AND `c` = :p2      →  a OR (b AND c)
```

That is what SQL means, and it is left as-is deliberately — silently re-grouping it would change every existing query. When you want the other reading, pass a **closure** to `where()` or `orWhere()`:

```php
->where('tenant_id', $tenant)
->where(fn ($q) => $q->where('owner_id', $user)->orWhere('public', 1))
// WHERE `tenant_id` = :p0 AND (`owner_id` = :p1 OR `public` = :p2)
```

Groups nest, and the sub-builder continues the outer query's placeholder numbering so bindings never collide. Column and operator validation applies identically inside a group. An empty closure adds nothing rather than emitting `()`.

Use a group rather than `whereRaw()` for this. Reaching for raw SQL to get parentheses was the previous advice, and it pushed authorization filters — the queries where grouping matters most — into the one method that accepts arbitrary SQL text.

The empty-`whereIn()` guard composes with grouping: an empty `IN` nullifies its own group, and an always-false group that is AND-joined nullifies the whole query.

### Ordering, grouping, pagination

```php
public function groupBy(string ...$columns): self
public function orderBy(string $column, string $direction = 'ASC'): self  // ASC|DESC only, else throws
public function orderByDesc(string $column): self
public function latest(string $column = 'created_at'): self
public function oldest(string $column = 'created_at'): self
public function limit(int $limit): self        // alias take()
public function offset(int $offset): self      // alias skip()
public function forPage(int $page, int $perPage = 15): self
public function paginate(int $page = 1, int $perPage = 15): array
```

`forPage()` sets offset + limit, clamping `$page` and `$perPage` to at least 1 — it is normally fed straight from `?page=`, so `?page=0` is user input rather than a programming error. `limit()`/`offset()` reject negative values outright (that *is* a programming error), and an `offset()` with no `limit()` compiles a sentinel `LIMIT 18446744073709551615`, since MySQL has no bare `OFFSET`. `paginate()` executes the query for one page and returns an array — there is no paginator object, and rendering page links is view-layer policy owned downstream:

```php
['data' => rows, 'total' => int, 'per_page' => int, 'current_page' => int,
 'last_page' => int, 'from' => int|null, 'to' => int|null]   // from/to null when the page is empty
```

- Both queries run on **clones** — the count with ORDER BY/LIMIT/OFFSET stripped (so `paginate()` after `orderBy()`/`limit()` still reports the full count), the data as a page slice — and **the builder itself is not mutated**: it stays reusable after `paginate()`. `$page`/`$perPage` clamp to ≥ 1; a page past the end returns empty `data` without executing the data query.
- `paginate()` **throws** with `groupBy()` or `distinct()` (the COUNT aggregate collapses groups / ignores DISTINCT, so the total would be wrong — compute the total yourself and use `forPage()`).

### Execution and aggregates

```php
public function get(): array                                      // list of assoc rows
public function first(): ?array                                   // applies limit(1), mutates the builder
public function value(string $column): mixed
public function pluck(string $column, ?string $key = null): array
public function count(string $column = '*'): int
public function sum(string $column): float
public function avg(string $column): float
public function min(string $column): mixed
public function max(string $column): mixed
public function exists(): bool
public function doesntExist(): bool
```

Aggregates replace the column list (internally via `RawExpression`) — use `clone()` when you need both an aggregate and rows from the same builder state.

### Writes

```php
public function insert(array $data): int                             // delegates to Connection::insert
public function upsert(array $data, array $updateColumns = []): int
public function update(array $data): int                             // affected rows
public function delete(): int
public function increment(string $column, int $amount = 1): int
public function decrement(string $column, int $amount = 1): int
```

**`update()` and `delete()` throw `RuntimeException` when the builder has no WHERE clause** — an accidental whole-table write is refused. To deliberately affect all rows, state it: `->whereRaw('1 = 1')`. `RawExpression` values in `update()` data are inlined, never bound (this is how increment/decrement compile to `col = col ± N`). `insert()` ignores accumulated wheres.

### Utility

```php
public function toSql(): string
public function getBindings(): array
public function clone(): self
```

### Unknown methods — the Laravel boundary

Calling any method the builder doesn't ship throws `BadMethodCallException` with a self-describing message. Common Laravel methods (`find`, `firstOrFail`, `chunk`, `with`, `whereHas`, `insertGetId`, `updateOrInsert`, `selectRaw`, `orderByRaw`, `when`) get a hint naming the local equivalent; everything else points here. This is deliberate — the builder is "Laravel light", not Laravel; a missing Laravel method is a scope boundary, not a bug.

### RawExpression

`KallioMicro\Database\RawExpression` (defined in `QueryBuilder.php`) wraps a string that compiles verbatim, bypassing binding and quoting. It exists for the builder's own aggregates and increment/decrement. **Never construct one from user input.**

---

## Security model summary

1. All values reach SQL through native prepared statements — the builder auto-binds everything.
2. Identifiers are allowlist-validated at acceptance and backtick-quoted at compile — every accepting method, including the whole `where`/`orderBy`/`groupBy` family. Quoting fails closed on anything it cannot quote. Operators are allowlisted wherever they are interpolated.
3. `whereRaw()` is the only escape hatch and enforces placeholder/binding parity.
4. Destructive writes require an explicit WHERE.
