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

The same command may be scheduled multiple times with different expressions — every entry is kept and runs when due (each shows as its own row in `--list`).

`schedule:run` is invoked by the host cron every minute:

```
* * * * * php /path/to/console schedule:run
```

Per tick it parses each task's 5-field cron expression (`min hour day month weekday`; supports `*`, exact values, `a-b` ranges, `a,b,c` lists, `*/n` and `a-b/n` steps — no named months/weekdays) and runs every due task **inline, sequentially**. Anything outside that grammar — a zero step (`*/0`), a step on a list (`10,20/2`) — is **never due**: malformed fields silently don't match rather than crashing the tick. `--list` prints a table with due-now status and next run; `--force` runs everything regardless of schedule. Exit `1` if any task failed.

### Overlap protection

Each task executes under a per-task non-blocking `flock` on `storage/framework/schedule-{sanitized-task}-{hash}.lock` (non-alphanumerics in the task name become `-`; an 8-char md5 suffix keeps sanitized collisions on distinct files — `task:backup` → `schedule-task-backup-a1b2c3d4.lock`):

- A task still running from a previous tick is **skipped** (reported in the summary as skipped, not failed; exit code unaffected). `--force` bypasses the due-check but **never** the lock. **Operational note:** a task wedged forever (hung DB connection) is skipped on every tick with exit `0` — monitor the `Skipped:` count in the summary line, not only the exit code, if a task must not silently stall.
- The kernel drops the lock automatically if the process dies — no stale-lock cleanup needed. Lock files are deliberately **never unlinked** (removing a file while another process holds its lock would let two holders "lock" different inodes of the same path); the persistent 0-byte files under `storage/framework/` are harmless.
- If a lock directory/file cannot be created, **that task** is counted as failed (exit `1`) and the remaining due tasks still run — a permissions problem on one lock file never starves the whole scheduler. Running the scheduler as a different user than the one that created the lock files is the usual cause.

> **Scope note — the lock is host-local, by design.** `flock` only serializes runs on one machine. A deployment invoking `schedule:run` on multiple hosts still needs a distributed lock inside the task (e.g. MySQL `GET_LOCK` held by the DB connection, which auto-releases if the process dies).
