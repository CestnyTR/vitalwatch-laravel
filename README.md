# cestnytr/vitalwatch-laravel

VitalWatch monitoring client for Laravel applications. Reports exceptions, slow requests and slow
SQL queries to a central VitalWatch Hub **without collecting personal data**.

> This package is installed in the monitored applications. The hub itself lives in a separate repository:
> [vitalwatch-hub](https://github.com/CestnyTR/vitalwatch-hub)

| | |
|---|---|
| **Package** | `cestnytr/vitalwatch-laravel` |
| **Supports** | Laravel 11 · 12 · 13 · PHP 8.2+ |
| **License** | MIT |
| **Status** | 🚧 In development (Phase 2) |

---

## Installation

```bash
composer require cestnytr/vitalwatch-laravel
php artisan vendor:publish --tag=vitalwatch
```

Add to your `.env`:

```env
VITALWATCH_ENABLED=true
VITALWATCH_URL=https://vitalwatch.example.com
VITALWATCH_KEY=project-key
VITALWATCH_SECRET=...
VITALWATCH_ENV=production
```

The service provider is loaded automatically through Laravel package discovery; no manual registration
is needed. Get the key and secret from the **Setup** tab of the VitalWatch panel.

### Verify the installation

```bash
php artisan vitalwatch:status
```

```text
  SDK version        0.2.6
  Latest version     0.2.6
  Enabled            yes
  Hub URL            https://hub.example.com
  Project key        my-project
  Secret length      64 characters
  Environment        production
  Release tag        3f2a1b4c5d6e (source: .git)

  ✓ Configuration OK.

  Only the hub panel can confirm that data actually arrives:
  the connection status on the project's row should read "Connected".
  The hub answers 204 even to an invalid signature, so this command cannot tell.
```

Do not skip this step. The costliest way this package can fail is by silently doing nothing: if the
secret was copied incompletely, nothing blows up and nothing is logged — the hub answers `204` even to
an invalid signature. An installation can stay broken for months, and the silence is mistaken for
"all good".

The command shows everything that is wrong locally: missing variables, a secret of the wrong length,
a hub URL without HTTPS. Also (0.2.3+):

- **Release tag** and where it came from, e.g. `3f2a1b4c5d6e (source: .git)`; `not set (source: none)` if nothing was found.
  The source is `VITALWATCH_RELEASE`, the name of an environment variable (`GIT_COMMIT` …), `.git` or `none`.
- **Latest version** — the newest stable release on Packagist. If the installed one is older, you get
  the warning `! Update available: composer require cestnytr/vitalwatch-laravel:^X.Y`;
  this is not an error and the exit code does not change. If Packagist cannot be reached, it prints
  `could not check`. On servers without network access, or in CI,
  `VITALWATCH_STATUS_OFFLINE=1` skips the check. This request is made only by the command; while the
  application runs, the package makes no other outbound requests.

```bash
php artisan vitalwatch:status --test         # sends a test event to the hub — shows up as an error in the panel
php artisan vitalwatch:status --vitalwatch   # liveness request only — no error in the panel
```

`--test` is the right choice when verifying a single installation. Use `--vitalwatch` in an update loop
that walks through dozens of installations: there, `--test` would leave dozens of fake errors in the panel.

Only the **hub panel** can confirm that data actually arrives: the connection status on the project's
row should read `Connected`. The command cannot tell this on its own, because the hub answers
valid and invalid signatures with the same response.

---

## What it collects

| Field | Example |
|---|---|
| `kind` | `exception` · **`fatal`** · `http_5xx` · `slow_request` · `slow_query` · `job_failed` · `command_failed` |
| `msg`, `exception_class`, `stack`, `file`, `line` | Error details (masked before sending) |
| `route` | **The pattern, not the actual id.** On request records (`slow_request`, `http_5xx`) together with the method: `GET /api/products/{id}`. On error records (`exception`, 0.2.3+) the path only — `/api/products/{id}` — with the method in a separate `method` field. For `job_failed` it is the job class, for `command_failed` the task name |
| `controller`, `method`, `status` | `ProductController@show`, `GET`, `500` |
| `duration_ms`, `memory_mb` | Performance |
| `query_count` | Number of SQL queries — N+1 detection |
| `slowest_query_sql` | **Normalized:** `select * from users where email = ?` |
| `release`, `php_version`, `framework_version` | Environment |

### Queues, scheduler and Octane (0.2.3+)

| Situation | What you get |
|---|---|
| Queue job that has run out of attempts | `job_failed` — the "Path" column shows the job class. Laravel then also passes the same error to the handler; only one record is kept |
| Job failing on an intermediate attempt | `exception` (every attempt) |
| Job that times out | `job_failed` — the message contains the job and the timeout, no stack (the process is killed by a signal). If it has run out of attempts, the row above applies and no second record is created (0.2.4+) |
| Failed scheduled task | `command_failed` — "Path": `artisan report:send`. Includes non-zero exit codes. The PHP binary path is stripped from the name and the message |
| App that only runs queues/the scheduler | The liveness ping is also sent when a job finishes, in the queue loop and when a task finishes; the cache is checked at most once per minute |
| Synchronous queue (`sync`, `deferred`) | The job runs inside the request; the request's measurements are not affected. A failed job still creates `job_failed` and flags the request |
| Octane | Request measurement is installed (detected via the `LARAVEL_OCTANE` environment variable — `octane:start` sets it; setups that start the server themselves must define it manually). The start time is taken from the moment of the request; counters are reset at the end of each request and when a queue job starts |

**Known limitations:**

- **`runInBackground()` tasks:** when they fail, Laravel does not fire `ScheduledTaskFailed`, so no
  `command_failed` is sent for them. If the task is an artisan command, an exception thrown by the
  command itself still arrives as `exception`.
- **An artisan task can create two records.** `Schedule::command()` runs the command in a separate
  process. If the command throws, that process reports the error itself as `exception` (with a stack),
  and the scheduler reports the non-zero exit as `command_failed`. Since they happen in different
  processes they cannot be deduplicated; this is accepted by design.
- **`job_failed` and `command_failed` bypass Laravel's reporting settings.** These records are created
  from queue/scheduler events; the handler's `dontReport`, `throttle` and exception mapping
  (`$exceptions->map()`) do not apply to them — only `vitalwatch.ignore` does. If you use mapping, the
  same failure can create one `job_failed` (original exception) and one `exception` (mapped exception).

### Fatal errors are a separate kind

Out-of-memory errors, timeouts and compile errors **do not throw exceptions** in PHP: the process dies
and the exception handler never runs. So the class of errors that actually takes a site down stayed
completely invisible when monitoring relied on `hookExceptions()` alone — the uptime probe saw the 500,
but nobody knew why.

They are caught with `register_shutdown_function` and sent with kind `fatal`. They are not sent as
`exception` because the two are different things: an exception is a condition the application can
catch, a fatal error means the server hit a limit — and this class needs to be filterable on its own.

When memory runs out, the reporting code itself has no room to run; so a 256 KB buffer is reserved at
boot and released at shutdown. Without the buffer the hook is installed, but silently fails exactly when
it is needed.

Only fatal types (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`) are sent.
Warnings and notices can be raised dozens of times per request and would flood the panel.

---

## Liveness ping

Every eight hours the package sends the hub a small request that **carries no events**.

The reason: the hub used to tell whether an installation was working only from the errors it received.
That backfired — a healthy application that never fails sent no events and was therefore reported as
"installation may not be working". Being healthy was penalized.

Now the request itself is the proof. As long as liveness requests arrive, the hub knows the installation
is up; when they stop, what it reports is actually true.

| | |
|---|---|
| Interval | 8 hours |
| Body | `{"events": []}` — carries no measurements |
| Endpoint | Same endpoint as events, no extra URL |
| Triggered by | An HTTP request (see below) |

The interval is a third of the hub's 24-hour silence threshold. If they were equal, a single missed
request — a deploy, a short outage — would make the installation look broken.

### Why it does not use the scheduler

The liveness request is sent during `terminate()`, i.e. **after the response has been sent to the
user**. If it is not due yet, only a cache read happens; the user waits for nothing.

Laravel's scheduler is not used because the package is installed in dozens of projects and a running
cron cannot be assumed in all of them. In a project without a scheduler the liveness request would never
be sent and the installation would silently look "broken" — the very bug it is meant to fix.

A second benefit of tying it to requests: **the hub's own uptime probe is a request too.** Even a site
with no visitors keeps its pulse thanks to the probe.

The cache key is scoped by the project key; two applications sharing a cache store do not suppress each
other's pulse.

Nothing is sent while `VITALWATCH_ENABLED=false` or when the configuration is incomplete.

---

## What it does not collect

These are design constraints and cannot be enabled through configuration:

- ❌ IP address
- ❌ User-Agent
- ❌ Request body (form data)
- ❌ Query string values
- ❌ Session data, cookies
- ❌ `user_id` (off by default unless explicitly enabled)
- ❌ Raw SQL literal values — queries are normalized

Before sending, patterns for e-mail addresses, Turkish national ID (TC kimlik) numbers, phone numbers,
IBANs and card numbers are masked in messages and stack traces.

---

## Behavior guarantees

The package **does not change** the behavior of the application it is installed in:

- It does not swallow exceptions or break the `throw` chain
- It does not try to report its own errors (risk of infinite loops)
- If the hub is unreachable, it gives up silently and raises no errors in the application
- Reporting does not hold up the user's request
- No hooks are installed while `VITALWATCH_ENABLED=false`

---

## Configuration

In `config/vitalwatch.php`:

| Setting | Default | Description |
|---|---|---|
| `slow_request_ms` | 1000 | Requests exceeding this duration are reported as `slow_request` |
| `slow_query_ms` | 500 | Queries exceeding this duration are reported as `slow_query` |
| `capture_user_id` | `false` | When enabled, data linked to an identifiable person is collected |
| `ignore` | 6 classes¹ | Exception classes that are not reported (subclasses match too) |
| `timeout` | 2 | Hub timeout in **seconds**. Values of 100 and above are treated as milliseconds; clamped to 0.1–10 s² |
| `release` | `VITALWATCH_RELEASE`, otherwise automatic³ | Release tag showing which deploy introduced an error |
| `env` | `VITALWATCH_ENV`, otherwise `APP_ENV` | `production` · `staging` · `local`. Aliases are mapped: `prod`/`live` → production, `stage`/`stg`/`preprod`/`uat` → staging, `dev`/`development`/`test`/`testing` → local. The hub rejects unrecognized values; the diagnostic command reports an error |

¹ `AuthenticationException`, `AuthorizationException`, `ValidationException`,
`TokenMismatchException`, `NotFoundHttpException`, `MethodNotAllowedHttpException`.
In addition, regardless of the list, **every error carrying a 4xx** (`HttpException` 400–499,
`ModelNotFoundException`) is not reported — even if the application logs it itself.
The only exception is `job_failed`: in a queue job, `ModelNotFoundException` is a failure of the job
itself, not of the caller, and is reported on purpose.

² The Node package expects milliseconds under the same name; `VITALWATCH_TIMEOUT=2000` means 2 seconds
here, not 2000 seconds. Fractional values (`0.5`) are supported. Invalid, zero, negative or infinite
values fall back to the default (2 s); the result is clamped to 0.1–10 s (`50` → 10 s,
`0.0001` → 0.1 s). The rule is the same across all SDKs.

³ **The release tag is detected automatically (0.2.3+).** `VITALWATCH_RELEASE` was left empty in almost
every installation, so the hub could not tell which deploy introduced an error. Our deploys are
`git pull`, so `.git` stays on the server and the tag can be read from it. Order of precedence (same as
the Node and Python packages):

1. `VITALWATCH_RELEASE` — whitespace trimmed, at most 64 characters, used as is.
2. The first non-empty environment variable among: `GIT_COMMIT`, `GIT_SHA`, `COMMIT_SHA`, `SOURCE_VERSION`,
   `VERCEL_GIT_COMMIT_SHA`, `RENDER_GIT_COMMIT`, `HEROKU_SLUG_COMMIT`, `CI_COMMIT_SHA`.
   If it looks like a commit (7–40 hex digits), the first 12 digits in lowercase; otherwise at most
   64 characters. Read at runtime; not affected by `config:cache`.
3. `.git` in the project root: `HEAD` → branch ref or `packed-refs`; a detached HEAD is the commit
   itself. If `.git` is a file (`gitdir: …`, submodule), the directory it points to is read.
   The first 12 digits of a valid 40-digit commit.
4. If none of these exist, nothing is sent.

No shell command (`git`) is run; only two small files are read. The result is not cached across
processes: an FPM worker survives a deploy and would keep reporting the old commit. Git worktrees
(`commondir`) are not supported — use `VITALWATCH_RELEASE` in that case.

### Error reporting does not delay the response (0.2.3+)

Errors and slow-query events raised during an HTTP request are not sent immediately; they are sent at
the end of the request (terminate), after the response has reached the user. Previously `report()` ran
before the response, and with a slow hub the user waited up to the timeout. If the process ends before
reaching terminate (exit, fatal error), pending events are sent at shutdown. Behavior in the console and
in queues is unchanged.

### One failure, one record

A failing request creates only an **`exception` record with a stack**; the measurement no longer sends a
separate `http_5xx` for the same request (0.2.3+). A 5xx returned without an error
(`response('...', 503)`) still arrives as `http_5xx`.

If the body exceeds the hub's 8 KB limit, first the stack is dropped, then the message beyond
200 characters, then the slowest query — the event still arrives, even without a stack.

---

## Security

Server-to-server delivery is signed with HMAC:

- `X-VitalWatch-Signature: sha256=<hmac_sha256(timestamp + "." + body, VITALWATCH_SECRET)>`
  — the timestamp is part of the signature; otherwise an old body could be replayed with a new timestamp
- `X-VitalWatch-Timestamp` — the hub rejects requests older than 5 minutes (replay protection)
- Signatures are compared with `hash_equals()`

**`VITALWATCH_SECRET` lives on the server only.** Never put it in the browser or any client-side code —
browser errors use the separate, key-based `t.js`.

---

## Versioning

Semver. The hub's ingest endpoint contract is kept backward compatible: new fields may be added,
existing fields are never removed. Older package versions keep working.

---

## Development

```bash
composer install
./vendor/bin/pest
./vendor/bin/pint
```

Releasing: `git tag v1.x.x && git push --tags` → Packagist updates automatically via webhook.
