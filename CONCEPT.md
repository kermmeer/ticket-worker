# Ticket Worker: concept

A workbench for last-line support in Jira. It keeps the open tickets of the spaces you
configured in one overview. When you ask, a Claude agent takes a ticket: it reads
everything on it, works out which system it is about, searches that system's code for what
went wrong, and proposes a fix. You then keep talking to that agent on that ticket until it
is solved or you close the session.

> **Status:** concept, 2 October 2026. Nothing is built yet. This file is the idea to agree
> on first. Once the [open questions](#17-open-questions) are answered it becomes the build
> brief, the way jira-outbox's README is.

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
- The agent changing code. It reads the systems and never writes to them.
- More than one Jira site, several users with roles, Jira webhooks.

---

## 2. Words used here

| Word | Meaning |
|---|---|
| **Space** | A Jira space. Jira's REST API still calls these *projects*, so the code says `project` wherever it talks to Jira. One Jira site, set in `.env`, serves all spaces. |
| **Ticket rules** | The JQL that picks which tickets of a space this tool looks at, for example only escalated ones. |
| **System** | A codebase tickets can be about, available as a folder the agent can read. One system can serve several spaces. |
| **System context** | A short Markdown brief per system: what it is, where things are, what users call things. Written by an agent, reviewed by you. |
| **Analysis** | The first scan of a ticket's problem. It ends in a proposal. |
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
 3  systems (folders)                          ├─ route    which system? (contexts)
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
   colour for the overview.
2. **Ticket rules.** Build the filter from the space's own fields (issue types, statuses,
   labels, components, whatever field marks last line), or write the JQL yourself. The tool
   always adds `project = KEY`. A live preview shows how many tickets match and the first
   few, so you see what you are about to get. A second rule says when a ticket counts as
   done. The default is Jira's *Done* status category.
3. **Systems.** Pick this space's folders. The picker only offers the roots set in `.env`,
   never the whole disk. Each system can get routing hints: Jira components, labels or words
   that point to it. A system that another space already uses is reused, context and all.
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
- **Known sharp edges:** problems that keep coming back. Empty at first; it grows from
  solved tickets (§8).

If the folder already has a `CLAUDE.md` or a decent README, the scan starts from that.

Each context records the commit it was written at. The tool shows how far the system has
moved since (*context is 214 commits behind*) and offers a refresh. A refresh gives the agent
the old context plus what changed since then, and asks it to update. Every version is kept,
so an edit or a refresh can be undone.

Contexts live in the tool's database, not in the folders. The folders may be checkouts that
something else resets, and writing into them would mean the agent changes systems after all
(see [open question 4](#17-open-questions)).

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

Each row shows the key, summary, space label, system (once known), Jira status, priority, age
and last update, the agent's state, and the cost so far. You can filter by space, system and
priority, and search by key or text. `/` jumps to search, `j`/`k` move up and down, `Enter`
opens a ticket.

Sync runs on a schedule (every 10 minutes by default), and each space has a *Sync now*
button. Tickets that no longer match the rules (solved, moved, relabelled) leave the overview.
If one still has an open session, the tool suggests closing it.

---

## 7. Analysing a ticket

Open a ticket and press **Analyse**. The steps show as a checklist that ticks off as the agent
works:

1. **Gather.** The tool fetches the ticket fresh: description, every comment, attachments,
   linked issues. It converts Jira's rich text (ADF) to Markdown and downloads the
   attachments. Everything goes into the ticket's workspace folder, where the agent works:
   `ticket.md` and `attachments/`. The agent reads screenshots and PDFs like any other file.
2. **Route.** Using the contexts of all the space's systems, the agent decides which system
   the ticket is about and says why. If it cannot choose between two, it says so and checks
   both. A matching routing hint (a component tied to a system) counts as a strong hint, not
   a rule.
3. **Dig.** In that system's code, it follows what happens in the situation the ticket
   describes, finds where it can go wrong, reads the config, and checks those files' git
   history for recent changes. Regressions are the most common cause at the last line.
4. **Propose.** The analysis ends in a proposal with a fixed JSON schema, so the screen can
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
| Reply draft | An answer to the reporter, in the ticket's language |
| Confidence | Low, medium or high, with a one-line reason |

Evidence that points to code opens a read-only viewer at that line, so you can check the
claim without leaving the ticket.

---

## 8. Working the ticket

After the proposal, the session stays open. You talk, and the agent carries on with the whole
investigation in mind: *look at the batch job instead*, *the reporter says it worked last
week*, *show the fix as a diff* (shown, never applied). When the conversation changes the
conclusion, **Update proposal** asks the agent to restate it in the proposal format. That
makes a new version, and the earlier ones stay visible.

- **New ticket activity.** If a comment arrives while the session is open, the ticket shows
  it and offers to pass it to the agent.
- **Stop.** You can stop a turn while it runs. The session stays, and you carry on from there.
- **Close.** Closing ends the session, and the conversation stays readable. *Reopen*
  continues the same conversation, memory included, even weeks later.
- **Done.** When the ticket leaves the rules in Jira, the tool suggests closing the session.
  First it asks the agent for a short closing note: what the cause was and what fixed it. The
  note stays on the ticket and, if you approve, is added to the system context's *known sharp
  edges*.

An open session costs nothing while nobody types. An agent only runs while a turn is in
progress (§10).

---

## 9. Back to Jira

Version 1 only reads from Jira. The next step adds writing, but only when you press the
button:

- **Post a reply.** You edit the reply draft in place and post it as a comment. On a Jira
  Service Management space, you choose between a public reply and an internal note.
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
  --add-dir /systems/billing /systems/portal \
  --append-system-prompt "<role, rules, and the contexts of the space's systems>" \
  --max-budget-usd 3
```

Later turns pass `--resume <uuid>` instead of `--session-id`.

- **Sessions.** The conversation lives in Claude Code's own session store, and the tool keeps
  only its ID. Two things must hold for that to work. A ticket's turns always run from the
  same workspace folder, because the CLI files sessions by working directory. And the CLI's
  config directory (`CLAUDE_CONFIG_DIR`) must sit on a persistent volume, or a container
  rebuild forgets every session.
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

- **Read-only, twice over.** The systems are mounted read-only into the worker, and the
  agent's tools cannot write to them anyway.
- **Ticket text is untrusted.** Reporters write the tickets, and an attachment can contain
  anything, including instructions aimed at the agent. The agent can only read code and talk
  to you, so the worst a hostile ticket can do is mislead the analysis. That is why it gets
  no network, no Jira tool and no write access. Keep it that way when adding features.
- **No secrets in reach.** Permission rules deny `.env*`, keys and storage folders. Better
  still, the agent reads git checkouts, which contain no `.env` to begin with.
- **Personal data.** Tickets carry names, email addresses and screenshots, and everything the
  agent reads goes to Anthropic's API. Check that the customers' and the employer's
  agreements allow that before the first real ticket ([open question 11](#17-open-questions)).
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
 worker · same image + Claude Code ────────────────────────────────────────────────
   schedule:work   ticket sync, every 10 minutes
   queue:work      one job per turn ─► claude -p
                                         cwd    shared/agent/tickets/SUP-1234/
                                         reads  /systems/*  (mounted read-only)
                                         calls  the Anthropic API
```

- **Stack, same as palantir:** Laravel 13, Inertia with Vue 3, Tailwind 4, and the Authentik
  login through Socialite.
- **The worker is new infrastructure.** Today the environment runs only php-fpm and nginx,
  and the queue is synchronous. The agent needs a long-running worker with the Claude Code
  binary, the systems mounted read-only, a persistent volume for the CLI's config and
  sessions, and the database queue. That changes `compose.yml` and `Dockerfile.deploy`,
  which live outside this repo ([open question 10](#17-open-questions)).
- **Live output by polling.** While a turn runs, the page asks once a second for the events
  after the last one it has. There is no websocket server to run. Reverb can come later if
  polling falls short.
- **Jira:** `POST /rest/api/3/search/jql` paged with `nextPageToken` for the sync,
  `GET /rest/api/3/issue/{key}` and its comments for gathering, and
  `GET /rest/api/3/attachment/content/{id}` for downloads. Basic auth with an API token, as
  in jira-outbox.
- **One thing to fix when the skeleton lands:** the toolbox's `.env` uses the old Laravel
  names `QUEUE_DRIVER` and `CACHE_DRIVER`. Laravel 13 reads `QUEUE_CONNECTION` and
  `CACHE_STORE`.

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
| `ANTHROPIC_API_KEY` | — | or a subscription token, see [open question 6](#17-open-questions) |
| `CLAUDE_BIN` | `claude` | path to the CLI inside the worker |
| `CLAUDE_MODEL` | `claude-opus-5` | a space can override it |
| `CLAUDE_EFFORT` | `high` | |
| `AGENT_MAX_PARALLEL` | `2` | turns running at once |
| `AGENT_TURN_BUDGET_USD` | `3` | default ceiling per turn; a space can override it |
| `SYSTEM_ROOTS` | `/systems` | the only places the folder picker offers |
| `SYNC_EVERY_MINUTES` | `10` | |

Everything per space (rules, systems, budgets, model) lives in the database and is edited in
the app.

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
| `bg` | `#F4F1EA` | `#111318` | page |
| `surface` | `#FBF9F4` | `#181B22` | cards, panes |
| `sunken` | `#EAE5DA` | `#0B0D11` | sidebars, code |
| `line` | `#D8D0C0` | `#2A2F3A` | borders |
| `ink` | `#1D1B18` | `#ECE7DD` | text |
| `muted` | `#5F5A51` | `#9C978C` | secondary text |
| `signal` | `#C2410C` | `#FF7A3D` | needs you, the main action |
| `working` | `#1D5FC9` | `#6FA0FF` | agent busy |
| `waiting` | `#8A5A00` | `#E2B04A` | waiting on someone else |
| `done` | `#2F6B4F` | `#6FC39A` | closed, confirmed |

Every text colour passes WCAG AA (4.5:1) on `bg` and `surface` in its own theme. There is
one exception: Day `signal` on `sunken` only reaches 4.1:1, so signal-coloured text goes on
paper or cards, never on the sunken shade. Labels on a signal-coloured button use the `bg`
colour (4.6:1 in Day, 7.2:1 in Night).

**Type.** Instrument Sans for the interface. Instrument Serif only for large headings, which
gives the case-file feel. JetBrains Mono for anything you would copy or match: ticket keys,
paths, commits, code. The fonts are self-hosted, with no font CDN.

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
| 0. Skeleton | Laravel 13, Inertia and Vue, Tailwind 4, Authentik login, design tokens, both themes, empty screens | the shell looks right in Day and Night, on desktop and phone |
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

---

## 17. Open questions

Answer by number. The value in brackets is what this design assumes until then.

1. **Jira.** Is it the same Cloud site as jira-outbox? Jira Software or Jira Service
   Management (which decides public reply versus internal note)? *[Cloud, same site]*
2. **Users.** Only you, or a team later? *[only you, with Authentik in front]*
3. **Systems.** Where do the folders live: checkouts already on this machine, or should the
   tool clone the repos itself and keep them fetched? Which branch is what production runs?
   *[the tool clones and fetches; `main`]*
4. **Contexts.** In the tool, as designed, or written into each folder as a file? *[in the tool]*
5. **Beyond code.** May the agent see more than code and git history: logs, a read-only
   database replica, the running app? *[code and history only]*
6. **Claude billing.** An API key (exact cost per ticket) or your subscription? *[API key]*
7. **Patches.** Should the agent ever prepare a patch, in a scratch copy, for you to apply?
   *[no; it can show a diff in the conversation]*
8. **Language.** Which languages are tickets written in? *[the agent talks to you in the
   language you write in; reply drafts follow the ticket's language]*
9. **Sync.** Is every 10 minutes enough, or should Jira webhooks come later? *[10 minutes]*
10. **Infrastructure.** May the worker container go into `compose.yml` and
    `Dockerfile.deploy` (outside this repo), with read-only mounts and the Claude Code
    binary? *[yes, once you say go]*
11. **Personal data.** Is sending ticket contents to Anthropic's API acceptable to the
    customers and the employer? *[must be yes before real tickets go in]*
