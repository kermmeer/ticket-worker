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
- [ ] The agent: best matches in the first prompt, the approved cases as files to grep, the
      proposal says which case it used (step 3); drafts a case when a ticket is done (step 4)
- [ ] Use counts and "did not fit" feedback, to know which cases to retire (step 5)

## Step 2: agent runner and systems

- [x] Worker containers `worker`, `scheduler` and `agent`, with health reports on Setup (2026-10-02)
- [x] Systems page: folders you push into, `updateInstead`, last push shown (2026-10-02)
- [x] `tools/push-systems.sh`: from your PC (VPN on) fetch every clone from GitLab and push it
      to the server, mirroring rewrites; `fetch` and `push` apart when the VPN is in the way (2026-10-02)
- [ ] Remove a system (its row and its folder)
- [ ] Turn runner: `claude -p` per turn, stream-json into `agent_events`, budget, stop
- [ ] Context scan per system; review, edit, versions; staleness against the last push

## Step 3: analysis

- [ ] Ticket workspace, gather, route, dig, proposal screen, code viewer
- [ ] Reply language picked next to *Analyse*: Auto (the ticket's), English, Nederlands, Français, Dansk, …

## Step 4: conversation

- [ ] Resume, stop, close and reopen, new activity, proposal versions
- [ ] Patches: a scratch copy in the workspace, `Edit`/`Write` there only, `git format-patch` to download

## Step 5: back to Jira, and learning

- [x] Tickets open in jira-outbox (`JIRA_OPEN_URL`, `JIRA_OPEN_LABEL`) (2026-10-03)
- [x] Brief for jira-outbox's draft API: `docs/OUTBOX-API.md`, also at `/docs/outbox` (2026-10-03)
- [ ] Hand reply drafts to the outbox once its API exists; show what became of them

- [ ] Reply to customer and internal note in service spaces; one comment kind in plain ones
- [ ] Transitions, the dummy/real switch, closing notes into contexts, cost overview

## Step 6: data tools

- [ ] Per system: read-only API calls and SELECT queries made by the tool, secrets kept by the tool
      (waits on open question B: can minas reach them?)

## Other

- [ ] A generic Docker setup in the repository for someone else's instance, with `APP_PASSWORD`
      as the gate when there is no Authentik in front (open questions C and D)
