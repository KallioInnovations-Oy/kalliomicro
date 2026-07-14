# Console

> Sources: `src/Console/`, the `console` entry script.

Entry point: `php console <command>`. The kernel is `KallioMicro\Console\Console`; commands extend `KallioMicro\Console\Command`.

## Registration

There is **no auto-discovery** — commands are registered explicitly in the `console` script:

```php
$console->registerClass(\App\Console\Commands\BackupCommand::class);
```

`registerClass()` validates the class extends `Command` and instantiates it with `new $class($console)` — commands are **not** container-resolved; pull dependencies at runtime via `$this->app()->make(...)`. Built-ins registered automatically: `list`, `help`, `schedule:run`.

## Command base class

```php
abstract class Command
{
    protected string $name;         // e.g. 'task:backup'
    protected string $description;
    protected string $usage;
    protected array $arguments;     // name => description (help output)
    protected array $options;       // name => description

    abstract public function handle(Input $input): int;   // return exit code
    protected function configure(): void;                  // optional init hook
    protected function app(): Application;
    // output shortcuts: line, info, success, warning, error (STDERR), comment,
    //                   table, ask, confirm, progressBar
}
```

`TaskCommand` extends `Command` for scheduled tasks: adds `protected string $schedule`, `getSchedule()`, and timestamped `logStart()` / `logComplete()` / `logFailed()` helpers.

Keep command names short — the `list` layout column is 25 characters; longer names degrade to single-space alignment.

## Argument parsing — `Input`

`--name=value` → option with value; `--flag` → `true`; `-x` → `true` (**short options never take values**); everything else → positional. Accessors: `getArgument(int $index, ?string $default)`, `first()`, `getOption($name, $default)`, `hasOption()`, `is($index, $value)`, `count()`, `isEmpty()`.

## Execution and exit codes

`Console::execute()` returns the command's int result (non-int → `0`); unknown command → `1`; `--help`/`-h` prints command help; any uncaught `Throwable` → message printed (trace with `--verbose`/`-v`), logged, exit `1`. Output helpers colorize when the terminal supports it (`error()` writes to STDERR).

## Scheduler

Schedules are **code-defined and in-memory** — cron expressions registered against command names in the `console` script:

```php
$console->schedule('task:backup', '0 2 * * *');
```

`schedule:run` is invoked by the host cron every minute:

```
* * * * * php /path/to/console schedule:run
```

Per tick it parses each task's 5-field cron expression (`min hour day month weekday`; supports `*`, exact values, `a-b` ranges, `a,b,c` lists, `*/n` and `a-b/n` steps — no named months/weekdays) and runs every due task **inline, sequentially**. `--list` prints a table with due-now status and next run; `--force` runs everything regardless. Exit `1` if any task failed.

> **Scope note — no overlap protection, by design.** The base scheduler assumes fast, idempotent tasks on a single host. If a task can run longer than its schedule interval, the next `schedule:run` tick starts a second concurrent instance — a deployment scheduling slow or non-idempotent work adds its own lock around task execution (e.g. MySQL `GET_LOCK` held by the DB connection, which auto-releases if the process dies).
