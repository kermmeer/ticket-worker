# Working on Ticket Worker

Ticket Worker is a workbench for last-line support in Jira. It lists the open tickets of the
spaces you set up, and on request a Claude agent analyses one against the code of the system
it is about and proposes a fix. **Read `CONCEPT.md` first**: it is the design, and it records
why. `TODO.md` is the running record: add to it, including work you finish.

This file is for whoever builds the tool, not for the agents the tool starts on tickets. Those
get their instructions from the code, and their workspace sits outside this repository
(`shared/agent/`), so this file never loads into them. Keep it that way.

## Running it

Two ways, both in `INSTALL.md`:

- **Docker** (`compose.yml`, `docker/`): the code is baked into the images. After a change,
  `docker compose up -d --build`. Artisan: `docker compose exec app php artisan …`.
- **On a machine with PHP 8.3+, Composer and Node**: `composer install`, `npm ci && npm run build`,
  `php artisan migrate`, then `php artisan serve` plus `php artisan queue:work --queue=default`,
  `php artisan queue:work --queue=agents --tries=1 --timeout=1800` and `php artisan schedule:work`.

Either way:

- **Vue and CSS changes need a build** (`npm run build`, or a Docker rebuild).
- Queue workers keep the code they started with: restart them after changing a job. **First
  check no agent turn or scan is running** (`agent_turns` and `systems.scan_state` queued or
  running): a restart cuts one off, and it was paid for.
- `.env.example` documents every setting; keep it in step with `config/`, with no real values.
- Tests: `php artisan test` (or `composer test`). They run on SQLite in memory and need no
  Jira, no Claude and no database server.

A machine can add rules of its own in `CLAUDE.local.md` next to this file: Claude Code reads it
after `CLAUDE.md`, and git ignores it. That is the place for one server's paths and commands.

## Rules the code does not tell you

**Colours by role, never by shade.** `page surface sunken line ink muted signal working waiting
done` swap between Day and Night in `resources/css/app.css`. A literal such as `bg-white` or
`text-neutral-600` is a hole in one of the themes. `/design` shows every role.

**A state is never only a colour, and nothing lives only in a `title=`.** A word or a shape
always comes with the colour, and a phone has no hover.

**Secrets are reported as set or not set, never shown.** The Setup page is where people look
when something breaks, so it is the page that ends up in screenshots. `SetupTest` guards it.

**Tests never reach Jira or Anthropic.** Fake HTTP and `Process`; `TestCase` calls
`Http::preventStrayRequests()`, so a request a test forgot to fake fails it. Never put a real ticket in a
test or a fixture: tickets carry customers' names, addresses and screenshots.

**Do not read this machine's config in a test.** The next machine's `.env` differs; set what a
test needs with `config([...])`.

**Systems arrive by push.** Your Git servers are out of reach (GitLab, behind a VPN), so the
code comes in by `git push` into `shared/systems/<name>`. Nothing in the tool may pull, clone
or otherwise reach a Git server, and the agent sees those folders read-only.

**`CONCEPT.md` is the design record.** When a decision in it changes, change the document in
the same commit.

Commit messages: a short subject and a line or two of why.
