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
