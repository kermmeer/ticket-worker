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

## Next, in order

1. **Pass the Authentik user to the app on minas.** One line in nginx, in the
   `@reverse_proxy` block (a `proxy_set_header` in `location /` does not carry over to a
   named location): `proxy_set_header X-authentik-username $authentik_username;`. Then
   `AUTH_HEADER=X-authentik-username` in `shared/.env`, and nobody signs in twice.
   *Effort: minutes; needs nginx access.*
2. **Mine, from Jira.** Link each account to its Jira user (by e-mail, looked up once) for
   an *Assigned to me* filter, and, with `JIRA_WRITE=real`, an *Assign to me* button.
   With many projects, "what is mine" matters more than "what is in this space".
   *Effort: half a day.*
3. **Settings per space.** Different projects, different customers: reply rules (tone,
   signature, language), the model and budget, and the quick scan's model per space,
   falling back to the instance's. *Effort: half a day.*
4. **Tell me when it is done.** An analysis takes minutes; nobody watches it. A browser
   notification when your turn finishes, and per space an optional Slack or Teams webhook
   for "quick scan says the data is still wrong" and for SLAs about to break.
   *Effort: a day.*
5. **Who may change what.** Today everyone may edit spaces, projects, APIs and the rules.
   Fine for a small trusted team; for more, an *editor* role: admins and editors change
   setup, everyone else works tickets. *Effort: half a day.*
6. **Read-only SQL per project.** The other half of data tools (CONCEPT.md §11): a
   read-only database account per project, SELECT only, row and time limits, every query
   in the log, the password kept by the tool. *Effort: a day.*
7. **Pull, where a host is reachable.** Not every repository sits behind the VPN. A
   per-project read-only deploy key and a nightly fetch, with push staying the way in for
   the rest. *Effort: half a day.*
8. **More than one Jira site.** If some teams' projects live in another Jira (a
   customer's, say), sites become a table and each space picks one. *Effort: a day.*
9. **Budgets.** A monthly ceiling per person and per space, with a warning at 80% and a
   stop at 100%, so a shared instance never surprises whoever pays. *Effort: half a day.*
10. **Hand-over.** *Take over* on a session someone else started, and a note to the next
    person when you close one. *Effort: hours.*
11. **An audit trail.** Who changed a space's rules, an API, the reply rules, and when.
    *Effort: hours.*

## Open questions

- **A. Shared or personal casebook?** Shared now: one team learns once. Per-space cases
  might suit very different projects better; tags per project may be enough.
- **B. Whose Claude runs automatic work?** Quick scans of new tickets have nobody asking
  and use the instance's sign-in. The alternative is the person who turned quick scans on
  for that space, so each project's owner pays for its own.
- **C. One board or many?** Everyone sees every space now, with *My spaces* as the
  default. A team that must not see another's tickets (other customers) needs spaces per
  group, which is a bigger step than anything above.
