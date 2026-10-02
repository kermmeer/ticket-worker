# Working on Ticket Worker

Ticket Worker is a workbench for last-line support in Jira. It lists the open tickets of the
spaces you set up, and on request a Claude agent analyses one against the code of the system
it is about and proposes a fix. **Read `CONCEPT.md` first**: it is the design, and it records
why. `TODO.md` is the running record: add to it, including work you finish.

This file is for whoever builds the tool, not for the agents the tool starts on tickets. Those
get their instructions from the code, and their workspace sits outside this repository
(`shared/agent/`), so this file never loads into them. Keep it that way.

## The environment (minas)

A toolbox dev tree, `/data/apps/ticket-worker-dev/work`, served live by php-fpm in a container.

```bash
sudo toolbox-dev ticket-worker-dev composer|migrate|test|up|status|patch
corepack npm@11 install && corepack npm@11 run build   # minas has no global npm
```

- PHP changes apply on the next request. **Vue and CSS changes need `run build`**: php-fpm
  serves `public/build`, and there is no Vite dev server here.
- Never run `php artisan` on the host in `work/`. What it creates under `storage/` is not
  writable by php-fpm, and every logged error then turns into a 500. Use the toolbox commands.
- `shared/.env` (linked as `work/.env`) holds the real settings and secrets. `.env.example`
  documents every setting; keep the two in step, with no real values in the example.
- Work leaves as patches (`toolbox-dev … patch`). Nothing here can push.
- Five containers: `app` (php-fpm), `web`, `worker`, `scheduler` and `agent`
  (`CONCEPT.md` §12). Their definitions live outside the repository, in
  `/data/apps/ticket-worker-dev/compose.yml` and `Dockerfile.deploy`.
- Queue workers keep the code they started with. After changing a job:
  `sudo docker compose -f /data/apps/ticket-worker-dev/compose.yml restart worker scheduler agent`.
- Artisan in a container, as the tree's owner (tinker needs a writable `HOME`):
  `sudo docker compose -f /data/apps/ticket-worker-dev/compose.yml --project-directory /data/apps/ticket-worker-dev exec -T --user 1000:33 -e HOME=/tmp -w /app/work app php artisan …`
- **Never `sed -i` `shared/.env`**, or anything else that replaces the file: the new file
  loses the `www-data` group, php-fpm can no longer read it, and every page fails with a
  missing APP_KEY. Append to it, or `sudo chgrp www-data` it afterwards.

## Rules the code does not tell you

**Colours by role, never by shade.** `page surface sunken line ink muted signal working waiting
done` swap between Day and Night in `resources/css/app.css`. A literal such as `bg-white` or
`text-neutral-600` is a hole in one of the themes. `/design` shows every role.

**A state is never only a colour, and nothing lives only in a `title=`.** A word or a shape
always comes with the colour, and a phone has no hover.

**Secrets are reported as set or not set, never shown.** The Setup page is where people look
when something breaks, so it is the page that ends up in screenshots. `SetupTest` guards it.

**Tests never reach Jira or Anthropic.** Fake HTTP and `Process`. Never put a real ticket in a
test or a fixture: tickets carry customers' names, addresses and screenshots.

**Do not read this machine's config in a test.** The next machine's `.env` differs; set what a
test needs with `config([...])`.

**Systems arrive by push.** Your Git servers are out of reach (GitLab, behind a VPN), so the
code comes in by `git push` into `shared/systems/<name>`. Nothing in the tool may pull, clone
or otherwise reach a Git server, and the agent sees those folders read-only.

**`CONCEPT.md` is the design record.** When a decision in it changes, change the document in
the same commit.

Commit messages: a short subject and a line or two of why.
