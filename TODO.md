# TODO

The running record: the build steps from `CONCEPT.md` §15, and open work as it comes up. Add
to it, including work you finish.

## Step 0: skeleton

- [x] Laravel 13, Inertia 3 with Vue 3, Tailwind 4 (2026-10-02)
- [x] Day and Night from role colours, self-hosted fonts, theme switch (2026-10-02)
- [x] Overview, Setup, Concept and Design pages; Setup reports what is configured (2026-10-02)
- [x] Sign-in: none. One person per instance, behind the gate in front of the site (2026-10-02)

## Step 1: Jira and spaces

- [x] Jira client: spaces, the space's words, permissions, search with `nextPageToken`,
      approximate count; retries on 429 and 5xx (2026-10-02)
- [x] Space setup: pick the space, its type from `projectTypeKey` with an override, ticket rules
      with the space's words to click and a live preview, the done rule, systems (2026-10-02)
- [x] For a service space, show whether the Jira account is an agent in it (2026-10-02)
- [x] Sync every 10 minutes and on demand; hyper mode (every minute) as a header switch (2026-10-02)
- [x] The overview grouped by what a ticket needs, filter by space, search with `/` (2026-10-02)
- [x] Status stamped under the key, a colour per status, recolourable per space; show one
      status only; full-width frame (2026-10-02)
- [x] Sleeping: statuses waiting on the requester, chosen per space (automatic until then),
      in a folded segment of their own (2026-10-02)
- [x] Created time, assignee and SLAs (time left or overdue, due date) in every row; sort by
      last update, newest or SLA (2026-10-03)
- [x] Which SLAs a space shows: all by default, untick to hide (2026-10-03)
- [x] Hide a ticket you will not take on; a folded Hidden segment to unhide it (2026-10-03)
- [x] Rule chips: several values of one field make `field in (…)`; the preview runs by itself
      and warns when nothing matches; the overview flags a space whose rules match nothing
      (2026-10-03, after `status = A AND status = B` emptied the first space)
- [ ] Issue and comments, attachments, ADF to Markdown: needed for the analysis (step 3)
- [ ] `j`/`k` to move through the overview, `Enter` to open

## The casebook

- [x] Cases (problem, symptoms, cause, fix, keywords, source tickets), draft, approved or
      retired; the Casebook page; *Write it up* from any ticket (2026-10-03)
- [x] Word matching against every open ticket on each sync and after each change; the overview
      shows the closest approved case (2026-10-03)
- [ ] Match on the description too, once it is synced (step 3)
- [x] The agent: best matches in the first prompt, the approved cases as files to grep, the
      proposal says which case it used (2026-10-04)
- [x] *Draft a case* on the ticket page: the agent drafts, the casebook form opens filled in, you
      review and write it down; nothing is saved before (2026-10-04)
- [ ] Offer *Draft a case* by itself when a ticket is done (step 4)
- [ ] Use counts and "did not fit" feedback, to know which cases to retire (step 5)

## Step 2: agent runner and systems

- [x] Worker containers `worker`, `scheduler` and `agent`, with health reports on Setup (2026-10-02)
- [x] Systems page: folders you push into, `updateInstead`, last push shown (2026-10-02)
- [x] `tools/push-systems.sh`: from your PC (VPN on) fetch every clone from GitLab and push it
      to the server, mirroring rewrites; `fetch` and `push` apart when the VPN is in the way (2026-10-02)
- [ ] Remove a system (its row and its folder)
- [x] Turn runner: `claude -p` per turn, stream-json into `agent_events`, budget, stop (2026-10-04)
- [ ] Workspace and transcript clean-up 30 days after a session closes (CONCEPT.md §11)
- [x] Context scan per system with an agent, read-only; rescan updates from the commits since;
      read and edit on the Systems page, every version kept; contexts go into every analysis (2026-10-04)
- [x] Queue retry window 2700 s: a long agent job was handed to a second worker at 90 s (2026-10-04); staleness against the last push

## Step 3: analysis

- [x] Ticket page; workspace (ticket.md from API v2, attachments, casebook files, git history
      per system); analysis with a proposal by JSON schema; follow-ups, update proposal, stop,
      close; reply draft to the outbox (2026-10-04)
- [x] Reply rules on the Setup page, sent with every turn that drafts a reply (2026-10-04)
- [x] Tokens next to cost per step, ticket and scan; a closed session leaves a one-line log of
      its work and price under Earlier sessions (2026-10-04)
- [ ] Code viewer for evidence
- [ ] Reply language picked next to *Analyse*: Auto (the ticket's), English, Nederlands, Français, Dansk, …

## Step 4: conversation

- [ ] Resume, stop, close and reopen, new activity, proposal versions
- [ ] Patches: a scratch copy in the workspace, `Edit`/`Write` there only, `git format-patch` to download

## Step 5: back to Jira, and learning

- [x] Tickets open in the outbox (`JIRA_OPEN_URL`, `JIRA_OPEN_LABEL`) (2026-10-03)
- [x] Brief for the outbox's draft API: `docs/OUTBOX-API.md`, also at `/docs/outbox` (2026-10-03)
- [x] Connected to the outbox's draft API over a shared Docker network (`OUTBOX_URL`, `OUTBOX_API_TOKEN`);
      Setup checks the token without creating a draft (2026-10-03)
- [x] The overview shows messages waiting in the outbox (scheduled, draft, failed), when they go,
      and the status they will set (`GET /api/v1/scheduled`, 30 s cache) (2026-10-03)
- [x] The overview refreshes itself every 30 s and on returning to the tab, quietly: no progress
      bar, filters and scroll kept (2026-10-03)
- [ ] Send the proposal's reply draft from the ticket page (step 3); show what became of it

- [ ] Reply to customer and internal note in service spaces; one comment kind in plain ones
- [ ] Transitions, the dummy/real switch, closing notes into contexts, cost overview

## Step 6: data tools

- [x] Per system: API connections (basic, bearer, header, query or none), secret encrypted and
      never shown, called by the tool for the agent through its MCP server (`agent:tools`):
      paths under the base only, GET unless POST allowed, 40 calls a turn, secret redacted,
      every call in the log (2026-10-06)
- [ ] SELECT queries on a read-only database account, the same way

## Other

- [x] Shareable: `compose.yml` + `docker/` with a MySQL of its own, an optional `APP_PASSWORD`
      gate, README and INSTALL.md, the server's own rules in an untracked `CLAUDE.local.md`,
      replies signed with the Jira account's first name (`{first_name}`), no outbox needed
      (Copy reply), no personal addresses left in the repository (2026-10-06)
- [x] Every overview section folds (Needs you to Hidden), remembered per browser; a search or
      status filter opens them all so no match stays folded away.
- [x] An icon: a ticket stub with a tick on the signal square, as favicon (svg + ico),
      apple-touch icon, web manifest and the header mark.
- [x] SLA, due next: a sort that puts deadlines still in time first and overdue-only tickets
      last, and a "Next to breach" strip with the five closest across every group.
- [x] "Draft and open in Outbox": hands the draft over and opens the outbox's link to it in a
      new tab; plain web addresses only, the ticket's outbox page when the link is missing.
- [x] Ticket text: Jira's wiki links (`[text|url]`, `[url]`, bare addresses) are clickable, and a
      label that is an address wins over a mail scanner's wrapper; `[^file]` and `!image!` link
      to the attachment. Attachments open or download through the app, which holds the token:
      images, PDF and text inline and sandboxed, everything else as a download (2026-10-06)
- [x] Patches: a code fix gets a patch file for `git am`, made by the agent in a scratch copy
      (`patch/<system>`), automatically after the analysis (Setup switch) or with *Prepare a
      patch*; diff view, how to test, risks (2026-10-06)
- [x] Answer rules (Setup → Answers): short, answer first, exact commands instead of open
      suggestions; sent with every turn. Proposals list their `commands` (reads/changes, where,
      undo) with Copy buttons; code blocks in answers copy too (2026-10-06)
- [x] Ticket comments newest first on the page (ticket.md keeps Jira's order); short dates
      carry the year when it is not this one (2026-10-06)
- [x] An expired sign-in in front of the app (Authentik) no longer fails silently: a network
      error asks /up, and a redirect there shows "sign-in expired, sign in again"; background
      refreshes pause; the message you were typing is kept across the reload; the manifest is
      fetched with cookies (2026-10-08)
- [x] Your notes for the agent: an optional box with Analyse; the note goes in as the
      engineer's lead (to follow and check, unlike ticket text), shows in the log as yours,
      and is kept in the browser until the analysis starts (2026-10-08)
- [x] Quick scan per space: new tickets get a first look by a lighter model as they arrive
      (kind, system, likely cause, next step; for data problems, whether it is still there via
      the system's API); one line on the overview, the whole scan on the ticket page, a button
      for older tickets (2026-10-09)
- [x] Batch quick scan from the overview (what is in view, without a scan), one at a time after
      any analysis, with an estimate first and Stop the rest; row links one per line (2026-10-09)
- [x] Jira's times were stored without their offset and came back 2 hours late ("updated in 2
      hours"): converted to UTC on sync; open tickets correct themselves at the next sync
- [x] A quieter frame: one slim header row, Concept, Outbox API and Design in the footer (2026-10-09)
- [x] API-only projects: a project that is just an API, added with its login in one form (2026-10-09)
- [x] Accounts for a team on one instance: sign in (or AUTH_HEADER), the first account is admin
      and adds people with a one-week link, each person's own Claude sign-in runs their work,
      "X is on it" on the overview, names in the log (2026-10-09)
- [ ] On minas: pass X-authentik-username to the app (nginx @reverse_proxy), then AUTH_HEADER
- [x] Workspaces and transcripts deleted 30 days after a ticket's work ended, daily (the promise
      in CONCEPT.md §11, until now unkept) (2026-10-09)
- [x] An API's stored secret is dropped when it moves to another server or sign-in (2026-10-09)
- [x] Follow spaces: the overview starts with yours; usage per person this month (2026-10-09)
- [ ] docs/TEAM.md: the team proposal, items 1–11 in order (written 2026-10-09)
- [x] Team, second round (2026-10-09): roles, space visibility, shared Claude sign-in, Mine from
      Jira, settings and budgets per space, notifications, read-only SQL, pulling, Jira sites,
      hand-over, audit trail, casebook tags. See docs/TEAM.md.
- [ ] On minas: openssh-client and pdo_pgsql in Dockerfile.deploy (SSH pulls, PostgreSQL)
- [x] Agents page: what works now and for whom, which Claude sign-in your work uses (the order,
      what is set, which is in use; never a token), your month, settings in force per space (2026-10-09)
- [x] Overview: the totals follow the space chosen; the space and Assigned to me are kept in the
      browser across refreshes and tabs (2026-10-10)
- [x] Phones: a bottom bar (Tickets, Agents, Casebook, You), compact overview rows with real
      buttons, forty per group then "Show more" (the overview was 205,000 px tall), the ticket
      page in one column that never runs wide, agent first, a jump bar (2026-10-10)
- [x] Database servers: one read-only login for every project database on a server; a project's
      database picks the server and its database from a list (2026-10-12)
- [x] Ticket log: a finished turn's steps fold into one line ("48 steps: 30 searches, 10 files
      read, 6 API calls"); live while it runs (2026-10-12)
- [x] Readable tickets (2026-10-12): a brief at the top (one line, cause, fix, the data, the next
      action as buttons); the full proposal folded above the reply; finished turns show only their
      answer; patch details folded. Agents: a one-line headline, limits per field, no running
      commentary, answers of about 120 words. Ticket 783: ~2,300 words on the page, now ~730.
- [x] Overview: search over key, summary, people, status, scan line and case, every word; a
      "Whose" filter (mine: assigned or created; or anyone seen, as assignee or reporter), kept
      like the space (2026-10-12)
- [x] Overview refresh: half the data (the row's scan only, no steps): 1 MB → 0.5 MB every 30 s
- [x] Casebook matching by words rare among the space's tickets, four of them at least: one
      case had matched 138 of 616 tickets on words like "boss"; now 12
- [x] A refused or failed page action shows a message instead of Inertia's modal error page,
      which looked like a stuck page; an expired page reloads itself
- [x] A case lists every ticket tied to it (strong / good / weak); "Not related" unties it for
      good, remembered through every rematch; "Link again" undoes it (2026-10-13)
- [x] An answer handed in just before the turn budget ran out was thrown away; kept now (2026-10-13)
- [x] Messages and notes take a pasted query result (100,000 characters), and a refused text says so
- [x] "Make a patch" in a message makes the patch file; the proposal's system found in "boss (billing)"
- [x] Costs per turn, not the conversation's running total ($11 showed as $57); corrected back
- [x] The ticket page is a chat: Ask on the lighter model, Dig deeper on the full one, a fresh chat
      after 30 quiet minutes with a summary of the last one; refused clicks show as a toast
- [x] Your own greeting and sign-off around every reply (Setup); the reply has its own section
- [x] The ticket page takes Jira's status on every visit; the sync looks up tickets that left
- [x] A space without systems gets none, not all; space pills count their open tickets; new rules
      sync at once; sections fold again while a status or search is picked
- [x] Chats look the data up first and find API endpoints in the code; a patch cut off by its
      budget keeps what it changed; tickets tied to a case by hand (2026-10-22)
- [x] Patches: `git am -3`, and `--keep-cr` for files with Windows line endings
- [x] `SIGN_IN=off` for an instance behind a gate of its own; a trusted header finds your account
      by username or email (2026-10-26)
- [x] Development work (2026-11-08): support or development by issue type; the chat starts from
      the ticket, not the scan's guess, and a correction settles it; Build on `feature/KEY` or
      `fix/KEY` into the system's repository here, with a user guide or technical note; Refine;
      the code at a glance per ticket and in the overview; development groups and a Kind filter
- [x] Agents at once, set on Setup: one agent container runs a pool (`agent:pool`)
- [x] Checks per system (lint, a type check, tests), each on or off; Node and Python downloaded
      by version; private npm packages and git repositories from copies pushed here
- [x] Systems can be renamed or follow another branch; a card says what arrived when nothing did;
      folded until opened; `push-systems.sh` finds nested clones and names what it pushed
- [x] Own work: started here, drafted into a Jira ticket by the agent with your screenshots, and
      moved onto that ticket when it is linked or made
- [x] Database servers through an SSH tunnel; openssh-client in the images on minas
- [x] The worker was down for 42 hours (2026-11-11 03:54 to 11-12 21:55): a full disk stopped
      its hourly restart, and 3,527 health checks and syncs piled up. The overview now warns when
      a space is three sync intervals late (half an hour at least). (2026-11-12)
- [x] What filled the disk on 2026-11-11: a one-off by hand, nothing in the tool (2026-11-12)
- [x] DEVBESUP-3151 went wrong from the start (2026-11-12): a fresh chat got the conclusion but not
      the query, and wrote a new one from guessed columns in five seconds without reading the code.
      The summary now carries the queries and commands; every turn must name only what it has seen,
      and read a table's definition before querying it. (2026-11-13)
- [x] ODOSSER-898 (2026-11-13): the ticket's own branch was on the server but out of the agent's
      sight (it reads the checked-out branch and runs no git). Branches named after the ticket now
      go into its workspace as commits and diff; Build continues on such a branch; "let's get it
      fixed now" is a build; the workspace is refreshed every turn, a failed refresh on a
      follow-up only noted.
- [x] On a phone, the whole menu behind ☰ beside the title (every page, New work, your account,
      the reference pages, hyper mode, day or night); the four-item bar stays. Every page checked
      at phone width (2026-11-14)
- [x] One API sign-in per system, shared by APIs set to "as the system"; per API read, read and
      query, or changes after approval: the agent proposes, an editor runs or refuses on the
      ticket, and the agent hears the outcome (2026-11-14)
- [x] Hyper mode on a schedule (workdays 08:00-17:00 by default when on): switches at the start
      and end only, a switch by hand holds; every open window follows it (2026-11-14)
- [x] Rescan by itself after a push, per system: ten minutes after the last push, at most once
      an hour (2026-11-14)
- [x] API docs (2026-11-14): from a served or committed OpenAPI spec, else written from the code
      by an agent; an APIs page for everyone; `api_docs` for the agent. All nine boss APIs
      written the same day: five from the code ($5.77 together, 5 to 194 endpoints), four from
      a spec in boss (1 to 5 endpoints).
- [ ] A spec in the code wins over the code, even when it covers one feature only: auth-api (3),
      inventory-api (2) and pcore-api (1) likely have more endpoints. Offer "write from the code"
      beside a spec, or merge the two.
- [x] Ticket handling that keeps track (2026-11-14, CONCEPT.md "Proposed on 14 November"):
      - [x] Seen per person; new activity and needs you mean "since your last look"; comments
            since then marked new on the ticket
      - [x] Chats close by themselves when the ticket leaves, or after 14 days without a turn
      - [x] Done: how it ended, whether the agent was right, a note; asked for once Jira
            resolves a worked ticket; the case drafted from it; the Agents page counts it
      - [x] Reply, status and assignee in one step, posted by Ticket Worker (`JIRA_WRITE=real`):
            the transitions Jira allows and the fields they ask, files, public or internal; the
            agent suggests status and assignee; a bare template is never posted
      - [x] Case links: a stricter score, a case per kind (support, development or both), Yes
            and Not this case wherever a hint shows, confirmed by an analysis that fits; only
            confirmed links group; one reply to all confirmed tickets of a case
      - [x] Your next ten, each with its reason; Mine (by Jira status) beside All (on trial)
      - [x] The ticket page in two columns on a wide screen: the work left, the ticket right
      - [x] The Design link out of the menu (the page and the document stay)
- [ ] Mine or All: decide after the trial which one the overview opens on, and whether All stays
- [ ] `composer audit` reports 8 advisories in 5 packages (seen 2026-11-14 when adding symfony/yaml)
