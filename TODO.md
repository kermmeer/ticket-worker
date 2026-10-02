# TODO

The running record: the build steps from `CONCEPT.md` §15, and open work as it comes up. Add
to it, including work you finish.

## Step 0: skeleton

- [x] Laravel 13, Inertia 3 with Vue 3, Tailwind 4 (2026-10-02)
- [x] Day and Night from role colours, self-hosted fonts, theme switch (2026-10-02)
- [x] Overview, Setup, Concept and Design pages; Setup reports what is configured (2026-10-02)
- [ ] Sign-in: rely on the Authentik gate in front of the site, or add the app's own
      Authentik login through Socialite as palantir does (open question 2)

## Step 1: Jira and spaces

- [ ] Jira client: search with `nextPageToken`, issue and comments, attachments, ADF to Markdown
- [ ] Space wizard: pick the space, ticket rules with a live preview, the done rule
- [ ] Sync on a schedule and on demand; the overview grouped by what a ticket needs

## Step 2: agent runner and systems

- [ ] Worker containers `worker`, `scheduler` and `agent` (`CONCEPT.md` §12), waiting on a go
- [ ] Systems: clone and fetch into `shared/systems/`, a read-only deploy key each
- [ ] Turn runner: `claude -p` per turn, stream-json into `agent_events`, budget, stop
- [ ] Context scan per system; review, edit, versions

## Steps 3 to 5

Analysis, conversation, and back to Jira: see `CONCEPT.md` §15.
