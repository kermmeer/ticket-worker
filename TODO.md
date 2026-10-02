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
- [ ] Issue and comments, attachments, ADF to Markdown: needed for the analysis (step 3)
- [ ] `j`/`k` to move through the overview, `Enter` to open

## Step 2: agent runner and systems

- [x] Worker containers `worker`, `scheduler` and `agent`, with health reports on Setup (2026-10-02)
- [x] Systems page: folders you push into, `updateInstead`, last push shown (2026-10-02)
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

- [ ] Reply to customer and internal note in service spaces; one comment kind in plain ones
- [ ] Transitions, the dummy/real switch, closing notes into contexts, cost overview

## Step 6: data tools

- [ ] Per system: read-only API calls and SELECT queries made by the tool, secrets kept by the tool
      (waits on open question B: can minas reach them?)

## Other

- [ ] A generic Docker setup in the repository for someone else's instance, with `APP_PASSWORD`
      as the gate when there is no Authentik in front (open questions C and D)
