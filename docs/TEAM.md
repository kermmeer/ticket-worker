# Ticket Worker for a team

What one shared instance needs when a team, with many projects of different kinds, works
its tickets in it together. Written 2026-10-09, when accounts arrived. The first part is
built; the rest is a proposal, ordered by what it unblocks, to be argued with.

## Built

- **Accounts** (CONCEPT.md §12). One person runs the instance; everyone signs in with
  their own account, or is known by the header of the sign-in in front (`AUTH_HEADER`).
  The first account is the admin and adds people with a one-week link.
- **Each person's own Claude.** A subscription token or API key per person, encrypted.
  What they ask for runs on it; the instance's sign-in is the fallback and what automatic
  quick scans use.
- **Who is on what.** "Stijn is on it" on the overview; names in the log; usage per
  person this month on *Your account*.
- **Follow spaces.** Each person follows the projects they work on; their overview starts
  with those (*My spaces*), the others one click away.
- **Projects of any kind.** Code you push (any Git host, VPN or not), API-only projects
  (an API and its login in one form), or both; quick scans for every new ticket, per space.
- **Safer secrets.** An API's secret never follows it to another server, so nobody with
  edit rights can redirect it to themselves.
- **Forgetting.** Workspaces and transcripts go 30 days after a ticket's work ended.

## Built after the first round (2026-10-09)

Decided by Karl the same day, item by item:

- **Roles.** Admin (everything, people and the global setup and docs included), editor
  (spaces, projects with their APIs, databases and pulling, the casebook, hyper mode),
  member (works tickets, follows spaces, proposes cases an editor approves). One role per
  person, for the whole instance.
- **Which spaces someone sees.** Every space by default; an admin can limit a person, on
  the person (People) or on the space (Who sees this space). A ticket of a space someone
  may not see does not exist for them, by any route.
- **A shared Claude sign-in**, set by an admin on Setup, for everyone without their own;
  their own always goes first, `.env` last. Automatic quick scans run on the shared one,
  and without one they do not run (open question B).
- **Mine from Jira.** Accounts link to their Jira user: by name on sight, by picking from
  the assignees seen, or by a search. *Assigned to me* on the overview; *Assign to me* on a
  ticket (with `JIRA_WRITE=real`).
- **Settings per space,** with defaults for every space on Setup: model, effort, budget per
  turn, the quick scan's model, effort and budget, automatic patches, reply and answer
  rules, and a monthly budget.
- **Notifications.** In the browser when your work is done; per space, a Slack or Teams
  webhook for data still wrong and SLAs within the hour.
- **Budgets,** per space and per person, none by default: a warning at 80%, no new agent
  work at 100% that month.
- **Read-only SQL per project,** for the agent through the tool, which holds the password.
- **Pulling** from reachable Git hosts, for editors and up: a read-only token over https,
  or a deploy key over SSH where the server has `ssh`.
- **More than one Jira site,** added on Setup; each space reads from its own.
- **Hand-over:** take over someone's session; leave a note when closing one.
- **An audit trail** of setup changes, secrets only as "changed".
- **Casebook tags** (open question A): a case is for every space unless tagged with the
  spaces it is for.
- Open question C: one board, with which spaces each person sees decided per person or per
  space, as above. The role stays global.

## Still open

- **Pass the Authentik user to the app on minas** (skipped for now): one line in the
  nginx `@reverse_proxy` block, then `AUTH_HEADER`.
- **SSH on minas.** Its image has no `ssh`, so pulling there works with tokens only until
  `openssh-client` is added to its `Dockerfile.deploy` (the repository's image has it).
- **PostgreSQL on minas.** Same: `pdo_pgsql` is in the repository's image, not minas's.
