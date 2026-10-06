# Ticket Worker

A workbench for last-line support tickets in Jira. It lists the open tickets of the Jira
spaces you set up, and on request a Claude agent analyses one against the code of the system
it is about: it reads the ticket and its attachments, digs through the code and its history,
and ends with a proposal (problem, cause, evidence, fix) and a reply draft in the reporter's
language. You talk it through with the agent until it is right, and you send the reply.

One person per instance: each person who uses it runs their own.

## What it does

- **Overview** of every open ticket, grouped by what it needs from you: needs you, working,
  new activity, not analysed, parked, sleeping (waiting on the requester) and hidden. Status
  colours, assignee, SLAs with time left, a *Next to breach* list and an *SLA, due next*
  sort. It syncs with Jira every 10 minutes, or every minute in hyper mode.
- **Spaces**: one per Jira project, with your own JQL rules, which statuses sleep, and which
  SLAs show. Jira Service Management spaces included.
- **Systems**: the codebases tickets are about. You push the code in from your own machine;
  Ticket Worker never reaches your Git server. An agent writes a short context for each
  system once, so every analysis starts from a map instead of from zero.
- **Analysis** of a ticket by Claude Code, read-only, with live progress, cost and tokens.
  Ask follow-up questions in the same session.
- **Reply drafts** by your rules (short, friendly, signed with your Jira first name), in
  the reporter's language or one you pick (English, Dutch, French, Danish, German). Copy
  them into Jira, or hand them to an optional outbox app (`docs/OUTBOX-API.md`).
- **Casebook**: solved problems written down as short cases, which agents check first. An
  agent drafts a case from a solved ticket; you review it and save it.
- Light and dark mode, and it works on a phone.

Nothing it does posts to Jira: it reads, and you send.

## Getting started

**[INSTALL.md](INSTALL.md)** sets up an instance with Docker, step by step: Jira, Claude, a
first space and a first system. It is written so you can also hand it to Claude Code and let
it do the steps with you.

In short:

```bash
git clone <this repository> ticket-worker && cd ticket-worker
cp .env.example .env              # fill in Jira, Claude, DB_PASSWORD, APP_PASSWORD
mkdir -p data/systems data/agent
docker compose run --rm app php artisan key:generate --show   # put it in .env as APP_KEY
docker compose up -d --build
```

Then open <http://localhost:8080> and follow the **Setup** page.

## What it needs

- Docker with Compose, on a machine that can reach your Jira site (Jira Cloud).
- A Jira account with an API token, that can see the spaces you want to work.
- Claude: an Anthropic API key, or a Claude Pro/Max subscription token
  (`claude setup-token`). INSTALL.md explains both and what each means for your data.
- SSH access to that machine from where your code is, to push the systems in.

## More

- [CONCEPT.md](CONCEPT.md): the design, and why. Also in the app under *Concept*.
- [docs/OUTBOX-API.md](docs/OUTBOX-API.md): the API an outbox app needs to take reply drafts.
- [AGENTS.md](AGENTS.md): working on the code (`CLAUDE.md` loads it for Claude Code).
- [TODO.md](TODO.md): what is built and what is next.
