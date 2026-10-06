# Installing Ticket Worker

This sets up your own instance with Docker. It takes about half an hour, most of it getting
tokens. Every step ends with a way to check it worked.

> **Using Claude Code to do this?** Point it at this file: "Follow INSTALL.md with me". The
> steps are written to be followed in order. Claude should ask you for every token and
> password and write them into `.env` itself, never into chat logs or other files, and never
> commit `.env`.

## 0. What you need

- A machine that stays on, with **Docker and Docker Compose** (`docker compose version`
  works). Linux is easiest; Docker Desktop on Mac or Windows works for trying it.
- That machine reaches your **Jira Cloud** site (`https://<site>.atlassian.net`) and
  `api.anthropic.com` over HTTPS.
- **SSH** into that machine from wherever your code is, to push the systems in (step 6). On
  the same machine, a plain folder path works too.

## 1. Get the code and the folders

```bash
git clone <this repository> ticket-worker
cd ticket-worker
cp .env.example .env
mkdir -p data/systems data/agent
id -u; id -g
```

Create `data/` yourself, as the user who will push code in: Docker would otherwise create it
as root, and the app could not write to it. Put the two numbers `id` printed into `.env` as
`UID=` and `GID=` (often 1000 and 1000).

## 2. Fill in `.env`

Open `.env` in an editor. The file explains every line; these are the ones that matter now:

| Setting | What to put |
|---|---|
| `APP_URL` | the address you will open it on, e.g. `http://localhost:8080` |
| `APP_PASSWORD` | a password for the app (see below) |
| `APP_BIND`, `APP_PORT` | `127.0.0.1` and `8080` keep it on this machine only |
| `DB_PASSWORD` | any long random string; the database is created with it |
| `JIRA_BASE`, `JIRA_EMAIL`, `JIRA_TOKEN` | step 3 |
| `ANTHROPIC_API_KEY` *or* `CLAUDE_CODE_OAUTH_TOKEN` | step 4 |
| `SYSTEMS_PUSH_BASE` | step 6 |

**The password.** The app has no user accounts: one instance, one person. Anyone who can
open the page can read your tickets and start agents that you pay for. So either set
`APP_PASSWORD`, or leave it empty **only** if something in front of the app already asks who
you are (an SSO proxy, a VPN-only network). With `APP_BIND=0.0.0.0` the app is reachable from
the network: then the password is not optional. For HTTPS, put a reverse proxy (Caddy,
nginx, Traefik) in front and set `TRUSTED_PROXIES` to its address.

A random value for `DB_PASSWORD`: `openssl rand -base64 24`.

## 3. Jira

1. Sign in to Atlassian with the account Ticket Worker will read as. It must be able to see
   the spaces you want to work; for a Jira Service Management space, it must be an agent
   there. Replies are signed with this account's first name.
2. Go to <https://id.atlassian.com/manage-profile/security/api-tokens>, **Create API token**,
   name it `ticket-worker`, copy it.
3. In `.env`: `JIRA_BASE=https://<site>.atlassian.net`, `JIRA_EMAIL=` the account's email,
   `JIRA_TOKEN=` the token.

Leave `JIRA_WRITE=dummy`. Ticket Worker does not post to Jira.

## 4. Claude

The agents are Claude Code, run inside the `agent` container. They sign in one of two ways:

**An API key** (billed per use; the Commercial Terms apply, so no training on your tickets,
kept 30 days):

1. Sign in at <https://platform.claude.com> and add credits or a payment method.
2. Worth doing: under Settings → Workspaces, create a workspace `ticket-worker` with a spend
   limit.
3. Settings → API keys → **Create key**, in that workspace. Copy it; it starts with `sk-ant-`
   and is shown once.
4. In `.env`: `ANTHROPIC_API_KEY=sk-ant-…`

**Or a Claude Pro/Max subscription** (uses your subscription's limits, shared with your own
Claude use):

1. On any computer where Claude Code is signed in with the subscription, run
   `claude setup-token` and copy the token.
2. In `.env`: `CLAUDE_CODE_OAUTH_TOKEN=…`, and leave `ANTHROPIC_API_KEY` empty (a key wins
   when both are set).
3. In claude.ai → Settings → Privacy, turn **off** *Help improve Claude*. With it on, the
   Consumer Terms let Anthropic train on what the agents read, ticket contents included.

Never copy your own `~/.claude` folder into the app: that would hand the agents your
settings, memory and other projects.

An analysis typically costs $1–3 at API prices. `AGENT_TURN_BUDGET_USD` caps one turn.

## 5. Start it

```bash
docker compose run --rm app php artisan key:generate --show
```

Put the line it prints (`base64:…`) into `.env` as `APP_KEY=`. Then:

```bash
docker compose up -d --build
docker compose ps
```

The first build takes a few minutes. All six services should be `running` (`agent` twice);
`db` should say `healthy`. The `app` container sets up the database on its first start.

**Check:** open `APP_URL`, sign in with `APP_PASSWORD`, and go to **Setup**. Every line should
be green, except the outbox (optional) and the systems (step 6). The queue and agent lines
can take a minute to turn green after a start. If something is red, the line says why; and
`docker compose logs app worker agent` shows the rest.

## 6. A first space and a first system

**A space** is one Jira project. In the app: **Spaces → Add a space**, pick the project,
adjust the rules until the preview shows the tickets you work, choose which statuses mean
"waiting on the requester", and activate it. The overview fills at the first sync.

**A system** is the code tickets are about. Ticket Worker never reaches your Git server: you
push the code to it.

1. In `.env`, set `SYSTEMS_PUSH_BASE` to `data/systems` as your own computer reaches it over
   SSH, e.g. `you@server:/srv/ticket-worker/data/systems`. Then `docker compose up -d`.
2. In the app: **Systems → Add system**, with a short name (`billing`) and its branch.
   The page then shows the exact commands for your clone, like:

   ```bash
   git remote add ticket-worker you@server:/srv/ticket-worker/data/systems/billing
   git push ticket-worker +refs/remotes/origin/main:refs/heads/main
   ```

3. Run them in your clone of that code, on your own computer.
4. Back in the app, **Scan it**: an agent reads the code once and writes a short
   context (a few minutes, a few dollars). Read it, and edit it if it is wrong.
5. Link the system to the space (**Spaces → edit**).

To keep systems up to date, `tools/push-systems.sh` fetches and pushes every clone with a
`ticket-worker` remote. Run it by hand or on a schedule. If your Git server is behind a VPN
that cuts you off from the Ticket Worker machine, run `push-systems.sh fetch` with the VPN on
and `push-systems.sh push` with it off.

**Check:** open a ticket, pick the reply language, press **Analyse**, and watch it work.

## 7. Optional

- **Reply rules**: Setup → Replies. `{first_name}` is the Jira account's first name.
- **An outbox** that takes reply drafts instead of copy and paste: `OUTBOX_URL` and
  `OUTBOX_API_TOKEN`, and the API it must offer in `docs/OUTBOX-API.md`.
- **Open tickets elsewhere** than Jira: `JIRA_OPEN_URL=https://…/{key}` and `JIRA_OPEN_LABEL`.

## Running it

| To | Run |
|---|---|
| change a setting in `.env` | `docker compose up -d` |
| upgrade | `git pull && docker compose up -d --build` |
| see what it is doing | `docker compose logs -f app worker agent` |
| stop it | `docker compose down` (the data stays) |
| back it up | the `db` volume (`docker compose exec db mysqldump …`) and `data/` |

**Before restarting or upgrading, check no analysis is running** (the overview's *Working*
group is empty): a restart cuts it off, and it was paid for.

`data/` and the database hold ticket contents, which include customers' names and
screenshots: back them up, and treat the backups as carefully as Jira itself.

## Without Docker

Possible, if you already run PHP apps: PHP 8.3+ with `pdo_mysql`, Composer, Node 22, MySQL
8, Claude Code on the PATH of the user that runs the agent queue, and ripgrep. Then
`composer install --no-dev`, `npm ci && npm run build`, `php artisan migrate`, a web server
on `public/`, and three long-running processes: `php artisan queue:work --queue=default`,
`php artisan queue:work --queue=agents --tries=1 --timeout=1800` and
`php artisan schedule:work`. The folders default to `../shared/` next to the checkout
(`SYSTEMS_PATH`, `AGENT_WORKSPACES_PATH`, `CLAUDE_CONFIG_DIR` move them).
