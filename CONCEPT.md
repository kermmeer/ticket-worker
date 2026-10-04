# Ticket Worker: concept

A workbench for last-line support in Jira. It keeps the open tickets of the spaces you
configured in one overview. When you ask, a Claude agent takes a ticket: it reads
everything on it, works out which system it is about, searches that system's code for what
went wrong, and proposes a fix. You then keep talking to that agent on that ticket until it
is solved or you close the session.

> **Status:** 4 October 2026. Built: steps 0 and 1, the casebook, the outbox connection, and
> the first agent: a ticket page where **Analyse** runs Claude Code read-only on the ticket
> and its systems and ends in a proposal (see the [build order](#15-build-order)). The answers to the first round of questions are
> recorded in [§17](#17-decisions-and-open-questions); with them this file is the build brief,
> the way jira-outbox's README is.

---

## 1. What it is for

Last-line tickets are the ones the earlier lines could not solve. They nearly always come
down to reading code: which system is this, what does it do in this exact case, what changed
recently. Most of the time goes into that reading, not into the fix. Ticket Worker does the
reading and comes back with a diagnosis you can check, with the evidence attached.

The agent works **for** you, not instead of you. It reads, investigates and drafts. You
decide, and you are the one who answers in Jira.

### Goals

- One overview of the open tickets in every configured space.
- One-time setup per space: which space, which tickets count, which systems are involved.
- A short context per system, written by an agent and checked by you. Every later agent
  then starts from a map, not a blank page.
- When you ask, an analysis of a ticket that ends in a concrete proposal with evidence: file
  and line, commits, quotes.
- A conversation with the agent on each ticket, open until the ticket is done or you close it.
- A look of its own, with a light and a dark theme.

### Not in v1

- Analysing tickets automatically as they arrive. Every analysis costs money, so you start it.
- The agent posting to Jira or changing tickets. It drafts; you post (with a button, in v1.x).
- The agent changing the systems. It reads them; a patch you ask for is written in a scratch
  copy of its own (§8), and you apply it.
- More than one Jira site, Jira webhooks, and accounts: an instance belongs to one person, and
  someone else who wants Ticket Worker runs an instance of their own.

---

## 2. Words used here

| Word | Meaning |
|---|---|
| **Space** | A Jira space: a service space (Jira Service Management) or a plain one. Jira's REST API still calls these *projects*, so the code says `project` wherever it talks to Jira. One Jira site, set in `.env`, serves all spaces. |
| **Ticket rules** | The JQL that picks which tickets of a space this tool looks at, for example only escalated ones. |
| **System** | A codebase tickets can be about: a folder on minas that you push the code into, and the agent reads. One system can serve several spaces. |
| **System context** | A short Markdown brief per system: what it is, where things are, what users call things. Written by an agent, reviewed by you. |
| **Analysis** | The first scan of a ticket's problem. It ends in a proposal. |
| **Casebook** | Solved cases: the problem as tickets show it, its cause, what fixed it. Written by you, or drafted by an agent and approved by you; matched against every open ticket (§8). |
| **Proposal** | The structured result: which system, what went wrong, the evidence, the fix, a workaround, a reply draft. Versioned. |
| **Session** | One Claude conversation tied to one ticket. It stays open until you close it. |
| **Turn** | One round in a session: your message, the agent working, its answer. |

---

## 3. The flow

```
 Once per space                              Per ticket, when you ask
 ──────────────                              ────────────────────────
 1  pick the space                           Analyse
 2  ticket rules (JQL, live preview)           ├─ gather   text, comments, attachments
 3  systems (pushed by you)                   ├─ route    which system? (contexts)
 4  context scan, one agent per system         ├─ dig      code and git history
 5  activate ─► sync ─► overview ─────────►    └─ propose  cause, evidence, fix, reply draft
                                                     │
                                               conversation ◄──► you
                                                     │
                                               ticket done in Jira, or you close it
```

---

## 4. Setting up a space

A space stays a draft until its setup is finished, and only active spaces sync tickets. Setup
is a short wizard. You can come back to any step later.

1. **Space.** Pick one of the spaces the Jira account can see. Give it a short label and a
   colour for the overview. Its type is read from Jira (a service space has the project type
   `service_desk`) and can be overridden here; it decides how replies are posted (§9).
2. **Ticket rules.** Build the filter from the space's own fields (issue types, statuses,
   labels, components, whatever field marks last line), or write the JQL yourself. The tool
   always adds `project = KEY`. Clicking several values of one field makes `status in (…)`:
   a ticket has one status, so `status = A AND status = B` would match nothing (it did, on
   3 October, and the overview emptied at the next sync). A live preview shows how many
   tickets match and the first few, so you see what you are about to get; it runs by itself
   when the page opens and after every save, and says so loudly when nothing matches. A second rule says when a ticket counts as
   done. The default is Jira's *Done* status category.
3. **Systems.** Pick the systems this space's tickets can be about, from the Systems page.
   A system is a folder on minas, `shared/systems/<name>`, that you push its code into from
   your own machine: Ticket Worker cannot reach your Git server (mostly GitLab, behind a VPN),
   so it never pulls. The folder is a checkout that accepts pushes to its branch
   (`receive.denyCurrentBranch=updateInstead`), so a push updates the files at once, history
   included, which is what the agent's `git log` and `git blame` need. Every context scan and
   analysis records the commit it read.

   Your PC is the bridge: it has the VPN (GlobalProtect) and can reach the server, and the
   server never runs the VPN. Each clone on the PC gets a second remote, `ticket-worker`,
   once. From then on `tools/push-systems.sh` (in this repository) fetches every such clone
   from GitLab and pushes it to the server, mirroring GitLab, rewrites included. Run it by
   hand or on a schedule. If the VPN blocks the server while connected, run `fetch` with
   it on and `push` with it off. A system can also get routing hints: Jira
   components, labels or words that point to it. A system that another space already uses is
   reused, context and all.
4. **Context scan.** For each system that has no context yet, an agent writes one (§5). You
   read each one, edit it if needed, and approve it. Scans run side by side up to the agent
   limit, and you can watch them work.
5. **Activate.** The first sync runs and the tickets appear in the overview.

*Later:* an agent reads the space's last N solved tickets and suggests which systems and
routing hints belong to it.

---

## 5. System contexts

A context is a map, not documentation. It is short enough to load into every session of the
space (aim for under 1,500 words per system) and concrete enough to send an agent straight
to the right file.

What a scan writes:

- **What it is:** two or three lines on what the system does and who uses it.
- **Stack:** languages, framework and versions, how it runs.
- **Map:** where routes, controllers, models, jobs, scheduled tasks, config and migrations
  live, and the naming conventions.
- **Words:** what users call things in tickets against what the code calls them:
  *factuur* → `Invoice`, *dossier* → `CaseFile`. Tickets are rarely written in the code's
  language, and this mapping is what makes routing and searching fast.
- **Data:** the main entities and how they connect.
- **Outside world:** integrations, queues, outgoing mail, files, external APIs.
- **Where to look:** what usually explains a bug in this system: logs, audit tables, status
  fields.
- **Known sharp edges:** the system's recurring problems in a line each, pointing to their
  cases in the casebook (§8).

If the folder already has a `CLAUDE.md` or a decent README, the scan starts from that.

Each context records the commit it was written at. The tool shows how far the system has
moved since (*context is 214 commits behind*) and offers a refresh. A refresh gives the agent
the old context plus what changed since then, and asks it to update. Every version is kept,
so an edit or a refresh can be undone.

**Built 2026-10-04:** *Scan it* on the Systems page, once the code is pushed. The scan shows
what it reads as it goes, costs a few dollars once, and every later analysis of a space with
that system carries the context in its instructions.

Contexts live in the tool's database, not in the folders. The folders hold exactly what you
pushed, and the agent sees them read-only; a context file inside one would be overwritten or
refused by your next push. The database keeps the versions besides.

---

## 6. The overview

This is the home page. It lists every open ticket in the active spaces, grouped by what it
needs from you:

| Group | Meaning |
|---|---|
| **Needs you** | A proposal is ready, or the agent asked you something |
| **Working** | An agent is busy on it right now |
| **New activity** | Someone commented on or changed the ticket since the last analysis |
| **Not analysed** | Synced, nothing started yet |
| **Parked** | Session closed, but the ticket is still open in Jira |
| **Sleeping** | Waiting on the requester: in a second segment below the rest, folded away |
| **Hidden** | Tickets you will not take on. *Hide* on the row puts them in a folded segment of their own; syncs leave them hidden until you unhide them |

Each row shows the key with the Jira status stamped below it, the summary, space label, system
(once known), priority, reporter, when it was created and last updated, who it is assigned
to, its SLAs, the agent's state, and the cost so far.

**SLAs** come from Jira Service Management with every sync: the time left or overdue, in the
SLA's own calendar (working hours) as Jira counts it, and when it falls due. A running SLA
shows first; paused, met and missed say so in words. Each service space chooses which SLAs it
shows: all of them by default, a new one in Jira included, until you untick it. The overview sorts by last update,
newest first, or the SLA closest to breaching (or furthest past it).
Every status has a colour of its own: Jira's grouping (to do, in progress, done) first, the
name second, so *Waiting for support* and *Waiting for customer* differ at a glance; the
space's setup can recolour any status. You can show one space or one status only, and search
by key or text. `/` jumps to search, `j`/`k` move up and down, `Enter` opens a ticket.

Which statuses sleep is part of each space's setup. Until you choose, it is automatic: any
status that says it waits for the customer, reporter or requester. A sleeping ticket wakes
up by itself when its status changes in Jira.

Sync runs every 10 minutes, and each space has a *Sync now* button. **Hyper mode**, a switch
in the header, makes it every minute, for when you are watching the queue closely. It stays on
until you switch it off, and the header shows it in the signal colour while it is on, so it is
never on by accident. Tickets that no longer match the rules (solved, moved, relabelled) leave the overview.
If one still has an open session, the tool suggests closing it.

---

## 7. Analysing a ticket

Open a ticket and press **Analyse**. Next to the button you pick the language of the reply
draft: *Auto*, the ticket's own language, unless you choose English, Nederlands, Français,
Dansk or another. The agent always talks to you in English; the instance has a setting for
that. The steps show as a checklist that ticks off as the agent works:

1. **Gather.** The tool fetches the ticket fresh: description, every comment, attachments,
   linked issues. It converts Jira's rich text (ADF) to Markdown and downloads the
   attachments. Everything goes into the ticket's workspace folder, where the agent works:
   `ticket.md` and `attachments/`. The agent reads screenshots and PDFs like any other file.
2. **Check the casebook.** The approved cases that match the ticket best come with the
   first prompt (§8). If one fits, the agent checks it against the ticket and the code, and
   the rest of the analysis is short: confirm, adapt, propose.
3. **Route.** Using the contexts of all the space's systems, the agent decides which system
   the ticket is about and says why. If it cannot choose between two, it says so and checks
   both. A matching routing hint (a component tied to a system) counts as a strong hint, not
   a rule.
4. **Dig.** In that system's code, it follows what happens in the situation the ticket
   describes, finds where it can go wrong, reads the config, and checks those files' git
   history for recent changes. Regressions are the most common cause at the last line.
5. **Propose.** The analysis ends in a proposal with a fixed JSON schema, so the screen can
   lay it out:

| Part | Content |
|---|---|
| Problem | The ticket in one or two sentences: what the reporter sees, and what they expected |
| System | Which one, and how sure |
| Cause | The most likely cause, plus any other candidates |
| Evidence | File and line, commits, quotes from the ticket or attachments, each one clickable |
| Fix | What to change and where: code, data or configuration |
| Workaround | What the reporter can do in the meantime, if anything |
| Questions | What the ticket does not say, and who should answer |
| Reply draft | An answer to the reporter, in the language picked; in a service space, marked as a public reply or an internal note |
| Casebook | The case it used and whether it fit, or that this one is new |
| Confidence | Low, medium or high, with a one-line reason |

Evidence that points to code opens a read-only viewer at that line, so you can check the
claim without leaving the ticket.

---

## 8. Working the ticket

After the proposal, the session stays open. You talk, and the agent carries on with the whole
investigation in mind: *look at the batch job instead*, *the reporter says it worked last
week*, *show the fix as a diff*. When the conversation changes the
conclusion, **Update proposal** asks the agent to restate it in the proposal format. That
makes a new version, and the earlier ones stay visible.

- **Patch.** *Prepare a patch* has the agent make the change in a scratch copy of the system
  inside the ticket's workspace, the one place it may write, starting from the commit it
  analysed. The tool turns that into a `git format-patch` file with a commit message the
  agent drafts: download it, `git am` it in your own checkout, test it, push it. The agent
  cannot run the code, so a patch is untested until you test it, and the page says so.
- **New ticket activity.** If a comment arrives while the session is open, the ticket shows
  it and offers to pass it to the agent.
- **Stop.** You can stop a turn while it runs. The session stays, and you carry on from there.
- **Close.** Closing ends the session, and the conversation stays readable. *Reopen*
  continues the same conversation, memory included, even weeks later.
- **Done.** When the ticket leaves the rules in Jira, the tool suggests closing the session.
  First the agent drafts a case for the casebook from what it learned: the problem, the
  cause, what fixed it. You approve it, edit it or throw it away.

An open session costs nothing while nobody types. An agent only runs while a turn is in
progress (§10).

### The casebook: solved cases for the next agent

What one ticket taught should not have to be learned again on the next. The casebook keeps it
as a case: the problem as tickets show it, the cause, and what fixed it.

- **Where cases come from.** You write them on the Casebook page, from scratch or from a
  ticket with *Write it up*. Once agents run, each solved ticket's agent drafts one from the
  conversation it already has, and you approve, edit or discard it. An agent never approves
  its own case.
- **What makes a good one.** Symptoms in the words reporters use, in every language tickets
  arrive in (the keywords count most); the cause with file and function, so the agent goes
  straight there; the fix as steps, including what to tell the reporter. No customer names
  or data: a case outlives the tickets it came from (§11).
- **Matching, for free.** Every sync compares each open ticket with the approved cases on
  shared words, weighted by where they appear and how rare they are. No model is asked, so it
  costs nothing, and the overview shows the closest case as a hint. Until descriptions are
  synced (step 3), only the summary is compared.
- **How the agent uses it.** The first prompt of an analysis carries the few best matches, a
  few hundred words, never the whole casebook. The approved cases also lie in the workspace
  as files with an index, so the agent can search for more. A case that fits turns an
  investigation into a check, which costs a fraction of the tokens and needs fewer questions
  to you. The proposal says which case it used and whether it fit.
- **Keeping it true.** Every use is counted. A case that keeps not fitting, or whose code has
  moved on, is retired: still readable, no longer matched or offered.
- **Considered and not chosen, for now:** embeddings (meaning-based search). Better with
  paraphrases and other languages, but a second paid service and a store to run. Word
  matching plus the agent's own reading goes first; embeddings come if the misses show.

---

## 9. Back to Jira

Version 1 only reads from Jira, and Ticket Worker will never post itself: replies go out
through **jira-outbox**, the app that already posts comments with mentions, files,
scheduling, status changes and assignees. Ticket Worker hands its reply over as a draft
(`POST /api/v1/drafts`), the draft waits in the outbox, and you review, edit and send it there.
What jira-outbox needs for that is briefed in [docs/OUTBOX-API.md](/docs/outbox). Tickets
already open there (`JIRA_OPEN_URL=https://jira.techfactory.dev/{key}`).

The overview also shows, per ticket, what is waiting in the outbox (scheduled, a draft, or
failed), when it goes, and the status sending it will set.

What the outbox will do with a draft, when you press the button:

- **Post a reply.** You edit the reply draft in place and post it as a comment. In a service
  space both are offered, **Reply to customer** and **Add internal note**, through the service
  desk API (`POST /rest/servicedeskapi/request/{key}/comment`, `public` true or false). The
  Jira account the tool uses must be an agent in that space for either to work; the space's
  setup checks it. A plain space has one kind of comment, visible to everyone who can open
  the ticket.
- **Transition and assign** from the ticket header.
- **A dry-run switch** in `.env` (`JIRA_WRITE=dummy|real`), like jira-outbox's `SEND_MODE`.
  In dummy mode nothing reaches Jira. That is how the tool is developed and demonstrated.

The agent itself never gets a way to write to Jira.

---

## 10. How the agent runs

The agents are Claude Code, run headless by the Laravel queue worker, **one process per
turn**. The first turn creates the session with an ID the tool chooses, and every later turn
resumes it. A turn looks roughly like this (flags as in Claude Code 2.1; the exact rules get
settled in the build):

```bash
claude -p "<this turn's message>" \
  --session-id <uuid> \
  --output-format stream-json --verbose \
  --model claude-opus-5 --effort high \
  --restricted --strict-mcp-config \
  --tools "Read,Grep,Glob,Bash" \
  --allowedTools "Read" "Grep" "Glob" "Bash(git log *)" "Bash(git show *)" "Bash(git blame *)" \
  --permission-prompts none \
  --add-dir /app/shared/systems/billing /app/shared/systems/portal \
  --append-system-prompt "<role, rules, and the contexts of the space's systems>" \
  --max-budget-usd 3
```

Later turns pass `--resume <uuid>` instead of `--session-id`.

- **Sessions.** The conversation lives in Claude Code's own session store, and the tool keeps
  only its ID. Two things must hold for that to work. A ticket's turns always run from the
  same workspace folder, because the CLI files sessions by working directory. And the CLI's
  config directory (`CLAUDE_CONFIG_DIR`) must sit on a persistent volume, or a container
  rebuild forgets every session. The CLI also deletes transcripts after 30 days by default
  (`cleanupPeriodDays`); the tool sets that to match its own retention (§11), or *Reopen*
  quietly stops working on older sessions.
- **The workspace** is `shared/agent/tickets/<KEY>/`, outside the repository. That keeps
  ticket data out of any checkout, and keeps the agent from loading this repo's own
  instructions, which are written for whoever builds the tool, not for the ticket agents.
- **Live output.** With `stream-json`, every step (a piece of text, a tool call, a tool
  result) arrives as one JSON line. The worker stores each line as an event, and while a turn
  runs the page polls for new events, so you watch the agent read. The last line reports
  what the turn cost and how many tokens it used.
- **What the agent can do.** It can read and search files and run a few read-only git
  commands. Anything else is refused without asking anyone (`--permission-prompts none`).
  Restricted mode also limits file access to the workspace and the `--add-dir` folders, and
  ignores settings files, so a `.claude/` folder inside a system's repo cannot add hooks or
  permissions.
- **The system prompt** holds its role, its rules and the contexts of the space's systems.
  It is recorded at the session's first turn and reused on every resume.
- **Patch turns** add `Edit` and `Write`, which restricted mode confines to the workspace,
  where the scratch copy is. The systems themselves stay read-only mounts.
- **The casebook** lies in the workspace as one Markdown file per approved case, plus an
  index, read-only; the best matches for the ticket also go in the first prompt.
- **Structured answers.** The analysis turn and *Update proposal* pass `--json-schema`, so
  their final answer is the proposal as validated JSON. Conversation turns stay plain prose.
- **Limits.** Each turn has a budget ceiling (`--max-budget-usd`, default set per space). At
  most `AGENT_MAX_PARALLEL` turns run at once, and only one per session; a turn whose session
  is busy waits.

Why one process per turn: a session waiting for you costs nothing (no process, no memory),
and it survives deploys and restarts, because all its state is in the session store and the
database. The price is a few seconds of start-up per turn, which is small next to the agent's
own work.

### Considered and not chosen

| Option | Why not, for now |
|---|---|
| One long-running CLI process per open session (`--input-format stream-json`) | Faster turns, but sessions stay open for days. That means dozens of idle processes that must survive deploys. Revisit if the start-up time gets annoying. |
| A sidecar on the Claude Agent SDK (TypeScript or Python) | Finer control (approval callbacks, interrupts), but it is a second app and runtime to run next to Laravel. The CLI covers what v1 needs. |
| The Messages API from PHP, with our own tools | We would rebuild file reading, search, image and PDF reading, and context management, all of which Claude Code already has. |
| Claude Managed Agents | Anthropic runs the loop and the workspace, so in its cloud setup the systems' code would have to be uploaded into Anthropic's sandbox. |

---

## 11. Boundaries

- **Read-only, twice over.** The systems are mounted read-only into the agent container, and
  the agent's tools cannot write to them anyway.
- **Ticket text is untrusted.** Reporters write the tickets, and an attachment can contain
  anything, including instructions aimed at the agent. The agent can only read code and talk
  to you, so the worst a hostile ticket can do is mislead the analysis. That is why it gets
  no open network, no Jira tool, and no write access outside its scratch copy. Data tools
  (next) only read. Keep it that way when adding features.
- **Data, when a system offers it.** Normally the agent sees code and history only. Some
  systems come with API definitions and a token to fetch data, or a read-only database
  account. For those the tool will offer data tools: GET requests to that system's configured
  API, and SELECT queries on its read-only account, each with a time and row limit, every call
  shown in the trail. The token or password stays in the tool: the agent asks the tool to make
  the call and never sees the secret, so none ends up in a transcript or at Anthropic. Keep
  tokens out of the code you push; put them in the system's settings instead.
- **No secrets in reach.** Permission rules deny `.env*`, keys and storage folders, and the
  systems hold what you pushed, which is committed code, not a server's `.env`.
- **Personal data.** Tickets carry names, email addresses and screenshots.
  - *On minas* they sit in the database, in the ticket's workspace (`ticket.md`,
    `attachments/`) and in the CLI's session transcripts, which are plain text. A ticket's
    workspace and transcripts are deleted 30 days after its session closes; its proposals and
    closing note stay. The database backups hold ticket data too.
  - *At Anthropic* goes only what the agent reads during a turn: the ticket text, the
    attachments it opens, and code. With an API key (Commercial Terms) Anthropic does not
    train models on it and keeps it for 30 days. With a Pro or Max subscription (Consumer
    Terms) it is used for training when the account's *help improve Claude* setting is on,
    and then kept for five years. That is why this design uses an API key. Zero data
    retention exists, but only for qualifying Enterprise organisations.
  - Claude Code's own usage metrics carry no prompts, code or file paths, and the agent
    container switches them off anyway (`CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1`).
  - Accepted on 2026-10-02 on these terms: an API key, no training, 30 days. A subscription
    token is possible too (§13), on the condition that *Help improve Claude* is off.
  - Cases in the casebook are kept as long as they are useful, so they hold no personal
    data: the agent writes them general, and you read them before approving.
- **Money.** There is a budget per turn, each ticket shows its cost, and the settings page
  shows the monthly total. In v1, only you start an agent.
- **Separate from your own Claude.** The worker runs Claude Code under its own config
  directory, without your settings, memory, plugins or hooks.

---

## 12. Architecture

```
 browser ── HTTPS, Authentik ──► nginx ──► php-fpm · Laravel 13 ◄── REST v3 ──► Jira Cloud
                                               │         ▲
                                     queue jobs│         │events, polled by the page
                                               ▼         │
                                         ┌───────────────────┐
                                         │       MySQL       │
                                         └───────────────────┘
                                               │         ▲
                                               ▼         │
 worker, scheduler, agent · the same image, the agent's with Claude Code ───────────
   schedule:work   ticket sync, every 10 minutes
   queue:work      one job per turn ─► claude -p
                                         cwd    shared/agent/tickets/SUP-1234/
                                         reads  shared/systems/*  (read-only)
                                         calls  the Anthropic API
```

- **Stack, same as palantir:** Laravel 13, Inertia with Vue 3, Tailwind 4.
- **No accounts.** One person per instance, so there is nothing to log in to inside the app.
  The site sits behind a gate instead: Authentik on minas. An instance without one gets a
  single password from `.env` (`APP_PASSWORD`, still to build).
- **The worker containers**: see below.
- **Live output by polling.** While a turn runs, the page asks once a second for the events
  after the last one it has. There is no websocket server to run. Reverb can come later if
  polling falls short.
- **SLAs:** they are custom fields of the type `com.atlassian.servicedesk:sd-sla-field`, found
  through `GET /rest/api/3/field` and asked for in the sync's search like any field.
- **Jira:** `POST /rest/api/3/search/jql` paged with `nextPageToken` for the sync,
  `GET /rest/api/3/issue/{key}` and its comments for gathering, and
  `GET /rest/api/3/attachment/content/{id}` for downloads. Basic auth with an API token, as
  in jira-outbox.

### The worker containers

Built 2026-10-02. Besides `app` (php-fpm) and `web` (nginx), three services run from the same
image, so they run the same code:

| Service | Runs | Sees |
|---|---|---|
| `worker` | `queue:work` for everything except agent turns: Jira sync, preparing the systems' folders | the whole app folder, like `app` |
| `scheduler` | `schedule:work`: the sync, the health reports, the clean-ups | the whole app folder |
| `agent` | `queue:work --queue=agents`, two replicas (`AGENT_MAX_PARALLEL`) | the code read-only, `shared/agent/` read-write, `shared/systems/` read-only, nothing else of `shared/` |

- `agent` builds a second stage of `Dockerfile.deploy`: the PHP image plus the Claude Code
  binary (a pinned version that never updates itself) and ripgrep. `app` builds the first
  stage, so php-fpm never carries the CLI.
- All three run as the tree's owner with the `www-data` group (`1000:33`), as the toolbox's
  own commands do, so whatever they write stays editable from both sides, including by your
  pushes.
- Paths are the same in every container (`/app/shared/systems/billing`), so a path stored in
  the database means the same thing everywhere.
- Nothing here can reach a Git server, and nothing needs to: systems arrive by your push.
- `agent` gets `CLAUDE_CONFIG_DIR=/app/shared/agent/claude` and
  `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1`. A deploy stops it with a grace period; a turn
  it interrupts is marked stopped, and the session carries on with your next message.
- **Health.** php-fpm cannot look into the other containers, so every minute the scheduler
  sends a small job to each queue, and whoever serves it leaves a report: the agent's says
  which Claude Code it has. The Setup page reads those reports.
- Queue workers keep the code they started with: after changing a job, restart them.

`compose.yml` and `Dockerfile.deploy` live outside this repository, in the environment's own
folder. Someone running their own instance needs an equivalent; a generic one for the
repository is on the list (§17).

### Data model

| Table | Holds |
|---|---|
| `spaces` | Jira project key, label, colour, ticket rules, done rule, agent settings, state (draft, scanning, active, paused), last sync |
| `systems` | Name, folder, current context and the commit it describes, scan state |
| `space_system` | Which systems serve which space, with the routing hints |
| `system_contexts` | Every version of every context, and who wrote it (agent or you) |
| `tickets` | Jira key and fields, raw payload, rendered Markdown, system, agent state, first and last seen, when it left the rules |
| `attachments` | Jira attachment metadata and the downloaded file |
| `agent_sessions` | Ticket or system (a context scan is a session too), Claude session ID, state, model, totals |
| `agent_turns` | Your message, state, start and end, cost, tokens, how it ended |
| `agent_events` | Everything the agent did in a turn, in order: the trail the page shows |
| `proposals` | Every proposal version, as the structured JSON |

---

## 13. Configuration

| Variable | Default | Notes |
|---|---|---|
| `JIRA_BASE` | — | `https://<site>.atlassian.net`, the one Jira site. Same names as jira-outbox |
| `JIRA_EMAIL`, `JIRA_TOKEN` | — | API token of the account the tool reads as |
| `JIRA_WRITE` | `dummy` | `real` lets the post and transition buttons reach Jira (later) |
| `ANTHROPIC_API_KEY` | — | an API key, not a subscription token: see §11 for why |
| `CLAUDE_BIN` | `claude` | path to the CLI inside the worker |
| `CLAUDE_MODEL` | `claude-opus-5` | a space can override it |
| `CLAUDE_EFFORT` | `high` | |
| `AGENT_MAX_PARALLEL` | `2` | turns running at once |
| `AGENT_TURN_BUDGET_USD` | `3` | default ceiling per turn; a space can override it |
| `SYSTEMS_PATH` | `shared/systems` | the folders you push the systems into |
| `SYSTEMS_PUSH_BASE` | — | the same folder as your machine sees it, for the push command, e.g. `kermmeer@192.168.3.89:/data/apps/ticket-worker-dev/shared/systems` |
| `AGENT_WORKSPACES_PATH` | `shared/agent/tickets` | one folder per ticket |
| `CLAUDE_CONFIG_DIR` | `shared/agent/claude` | the CLI's sessions; must outlive the container |
| `SYNC_EVERY_MINUTES` | `10` | |

### Getting an Anthropic API key

1. Sign in to the Claude Console at [platform.claude.com](https://platform.claude.com), or
   create an account there. The API is billed on its own, not through a Claude subscription:
   add credits or a payment method in the Console's billing settings first.
2. Worth it: create a workspace for Ticket Worker
   ([Settings → Workspaces](https://platform.claude.com/settings/workspaces)) and give it a
   spend limit, so its usage and cost show on their own and can never run away.
3. Go to [Settings → API keys](https://platform.claude.com/settings/keys) and click
   **Create key**. Name it after the instance (`ticket-worker-dev`), choose an expiration,
   link it to yourself (a personal key, for your own instance) or to a service account (for
   one others rely on), and scope it to the workspace from step 2.
4. Copy the key. It starts with `sk-ant-` and is shown **only once**; lose it and you create
   a new one.
5. Add it to `shared/.env` as `ANTHROPIC_API_KEY=sk-ant-…` with an editor. Afterwards the
   file must still be group `www-data`: if pages fail with a missing APP_KEY, run
   `sudo chgrp www-data /data/apps/ticket-worker-dev/shared/.env`.
6. Restart the queue containers so they read it:
   `sudo docker compose -f /data/apps/ticket-worker-dev/compose.yml restart worker scheduler agent`.
   The Setup page then shows the key as set.

Calls with this key fall under Anthropic's Commercial Terms: no training on them, kept for
30 days (§11).

### Or a Claude subscription instead of a key

The agent can also sign in with a Claude subscription (Pro or Max), through a token made for
unattended use. Never by sharing the login of the Claude Code you use yourself: that would
hand the ticket agents your settings, memory and every other project.

1. On any machine where you are signed in to Claude Code with the subscription, run
   `claude setup-token` and copy the token it prints.
2. Add it to `shared/.env` as `CLAUDE_CODE_OAUTH_TOKEN=…` (leave `ANTHROPIC_API_KEY` empty: a
   key wins when both are set), and restart the queue containers as above.

What changes: analyses draw on the subscription's usage limits, the same ones your own Claude
use draws on, so a busy day of tickets can run you into a limit, and the reverse. Costs on the
page are then what the work *would* have cost on the API. And the Consumer Terms apply: turn
off *Help improve Claude* in the claude.ai privacy settings, or Anthropic may train on ticket
contents and keep them for five years (§11).

Everything per space (rules, type, statuses, systems, budgets, model) lives in the database
and is edited in the app. Nothing outside `.env` may assume minas: another person's instance runs on
their own machine with their own settings.

---

## 14. Design

The look is a **case file**. Each ticket is a case, the agent's findings are evidence, and
the conversation reads as a log, not as chat bubbles. It is calm, dense where you scan (the
overview) and roomy where you read (the ticket). It should not look like another SaaS
dashboard.

**Two themes, both designed rather than one inverted from the other:**

- **Day:** warm paper, black ink, one signal colour.
- **Night:** blue-black, paper-white text, with the signal brightened so it stands out.

The header has a *System · Day · Night* switch. The choice is remembered per browser and
applied before the first paint, so the page never flashes the wrong theme.

| Token | Day | Night | Used for |
|---|---|---|---|
| `page` | `#F4F1EA` | `#111318` | page |
| `surface` | `#FBF9F4` | `#181B22` | cards, panes |
| `sunken` | `#EAE5DA` | `#0B0D11` | sidebars, code |
| `line` | `#D8D0C0` | `#2A2F3A` | borders |
| `ink` | `#1D1B18` | `#ECE7DD` | text |
| `muted` | `#5F5A51` | `#9C978C` | secondary text |
| `signal` | `#C2410C` | `#FF7A3D` | needs you, the main action |
| `working` | `#1D5FC9` | `#6FA0FF` | agent busy |
| `waiting` | `#8A5A00` | `#E2B04A` | waiting on someone else |
| `done` | `#2F6B4F` | `#6FC39A` | closed, confirmed |

Every text colour passes WCAG AA (4.5:1) on `page` and `surface` in its own theme. There is
one exception: Day `signal` on `sunken` only reaches 4.1:1, so signal-coloured text goes on
paper or cards, never on the sunken shade. Labels on a signal-coloured button use the `page`
colour (4.6:1 in Day, 7.2:1 in Night).

**Status labels** use seven more colours, `--tone-grey` to `--tone-teal`, each at least
4.5:1 on page, surface and sunken in both themes. **The frame is full width**; text keeps its
own reading measure.

**Type.** Geist for everything you read, with headings in the same face set heavier and
tighter, and Geist Mono for anything you would copy or match: ticket keys, paths, commits,
code. The fonts are self-hosted, with no font CDN. (Until 2026-10-02 the headings were
Instrument Serif; it read as editorial rather than modern.)

**Signature pieces**

- **Case header:** the key stamped in mono, the summary large, and a five-step state rail:
  synced · analysed · proposed · working · closed.
- **Activity line:** under a running turn, one mono line showing what the agent is doing
  right now, like `reading app/Services/CreditNoteService.php · 14 files read · 3 searches`.
  Expand it for the full trail.
- **Evidence chips:** `CreditNoteService.php:212` opens the code viewer at that line.
- **Log-style conversation:** time and author in the margin, full-width entries. The agent's
  entries and yours differ by the colour of a rule, not by bubble shape.
- **No state by colour alone:** a word or an icon always comes with it.
- **Motion only for *working*:** a slow scanning edge on the ticket being worked on. It is
  switched off under `prefers-reduced-motion`.

**The ticket screen**

```
┌─ SUP-1234 · Invoices not sent after a credit note ───────── ● Needs you · System: billing ─┐
│ TICKET                 │ LOG                                     │ PROPOSAL v2 · high      │
│ Reporter · 3 d · High  │ 14:02 agent  The credit note path       │ Cause                   │
│                        │              returns before the mail    │   apply() returns early │
│ Description            │              ▸ 6 files read, 2 searches │ Evidence                │
│ …                      │ 14:05 you    What about the batch job?  │   CreditNoteService:212 │
│ Comments (4)           │ 14:06 agent  reading Jobs/SendInvoice ▮ │   commit 9f3e1c2        │
│ Attachments ▣ ▣        │                                         │ Fix, workaround, reply  │
│                        │ [ message the agent …  ]  Send   Stop   │ $1.84 so far            │
└────────────────────────┴─────────────────────────────────────────┴─────────────────────────┘
```

**On a phone:** the overview is a list. A ticket becomes three tabs (Ticket · Log ·
Proposal), with the message box pinned to the bottom. Nothing is shown only on hover or in a
`title=`, because a phone has no hover.

---

## 15. Build order

Each step ends in something usable.

| Step | What | Done when |
|---|---|---|
| 0. Skeleton | Laravel 13, Inertia and Vue, Tailwind 4, design tokens, both themes; Overview, Setup, Concept and Design | the shell looks right in Day and Night, on desktop and phone. **Built 2026-10-02.** |
| 1. Jira and spaces | Jira client, space wizard with the space type and a live preview, sync with hyper mode, overview; the casebook and its matching | the overview lists exactly what the JQL lists in Jira. **Built 2026-10-02**, the casebook 2026-10-03; until step 3 a ticket opens in Jira |
| 2. Agent runner and systems | worker containers and the Systems page (**built 2026-10-02**); turn runner, event trail on screen, context scans, review and edit | a scanned context is one you would hand to a new colleague |
| 3. Analysis | ticket workspace, gather, the casebook in the prompt and as files, route, dig, reply language, proposal screen, code viewer | the backtest (§16) gets most real tickets right |
| 4. Conversation | resume, stop, close and reopen, passing on new activity, proposal versions, patches, the agent's case drafts | a ticket can be worked in the tool from first look to closing note |
| 5. Back to Jira, and learning | reply to customer and internal note, transitions, the dummy/real switch, use counts and retiring cases, cost overview | a whole ticket handled without opening Jira |
| 6. Data tools | per system, read-only API calls and SELECT queries made by the tool, secrets kept by the tool | an analysis checks a claim against real data without ever seeing a token |

---|---|---|
| 0. Skeleton | Laravel 13, Inertia and Vue, Tailwind 4, Authentik login, design tokens, both themes, empty screens | the shell looks right in Day and Night, on desktop and phone. **Built 2026-10-02, except the login.** |
| 1. Jira and spaces | Jira client, wizard steps 1–2 with live preview, sync, overview | the overview lists exactly what the JQL lists in Jira |
| 2. Agent runner and systems | worker container, turn runner, event trail on screen, systems, context scans, review and edit | a scanned context is one you would hand to a new colleague |
| 3. Analysis | ticket workspace, gather, route, dig, proposal screen, code viewer | the backtest (§16) gets most real tickets right |
| 4. Conversation | resume, stop, close and reopen, passing on new activity, proposal versions | a ticket can be worked in the tool from first look to closing note |
| 5. Back to Jira, and learning | post reply, transitions, dummy/real switch, closing notes into contexts, cost overview | a whole ticket handled without opening Jira |

---

## 16. How we'll know it works

**Backtest on solved tickets.** Take ten solved tickets whose real cause we know. Give the
agent each ticket as it was when it arrived (description and early comments only, nothing
that gives the answer away), against the code as it was then (the commit before the fix),
so the answer is not sitting in the code. Then compare the proposal with what the fix really
was. Did it pick the right system? The right cause? Was the evidence useful? Record cost and
time per analysis in the same table. Run it again after every change to the prompts or the
contexts. It is the only honest way to tell whether a change helped.

Run it with and without the casebook as well: the difference in tokens, time and questions
asked is what the casebook is worth.

---

## 17. Decisions and open questions

### Decided on 2 October 2026

1. **Jira.** Service spaces and plain ones both. The type comes from Jira and can be
   overridden in the space's settings. A service space offers both a public reply and an
   internal note, and the Jira account must be an agent in it (§9).
2. **Users.** One person per instance. Someone else runs an instance of their own, never a
   shared one, so the app has no accounts (§12).
3. **Systems.** Folders on minas that you push into (§4). Ticket Worker never reaches GitLab.
4. **Contexts.** In the tool (§5).
5. **Beyond code.** Normally code and history only. Systems with an API or a read-only
   database get data tools in step 6, with the secrets kept by the tool (§11).
6. **Claude billing.** An API key.
7. **Patches.** Yes: written in a scratch copy, downloaded as a file for `git am`, applied,
   tested and pushed by you (§8).
8. **Language.** Tickets are mostly English, some Dutch, French and Danish. The agent talks
   to you in English; the reply draft follows the ticket unless you pick another language
   before *Analyse* (§7).
9. **Sync.** Every 10 minutes, and every minute in hyper mode (§6).
10. **Infrastructure.** The worker containers, built (§12).
11. **Personal data.** Acceptable with an API key: no training, kept 30 days (§11).

### Still open

The value in brackets is what the design assumes until then.

- **A. Pushing to minas.** *Answered 2026-10-04:* yes, by IP; the name `minas` does not resolve
  on the PC, so `SYSTEMS_PUSH_BASE` uses `kermmeer@192.168.3.89`. The web address cannot take a
  push: it serves the site, behind Authentik.
- **B. Data tools.** Can minas reach those APIs and databases, or are they behind the same VPN
  as GitLab? If they are, the data tools need another way in. *[unknown; step 6 waits for it]*
- **C. Other people's instances.** Should the repository carry a generic Docker setup, so
  someone can run an instance without the minas toolbox? *[yes, before anyone needs it]*
- **D. A gate of its own.** An instance without Authentik in front asks for one password from
  `.env`. *[yes, together with C]*
