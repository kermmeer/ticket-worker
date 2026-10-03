# Brief for jira-outbox: reply drafts from Ticket Worker

Paste this into a session working on jira-outbox.

## Why

Ticket Worker is another app on minas (`/data/apps/ticket-worker-dev`). Its agents analyse
last-line support tickets and draft a reply for each. It never posts to Jira itself, and it
should not learn how: jira-outbox already posts comments, with mentions, files, scheduling,
status changes and assignees, and it is where the person reviews what goes out.

So Ticket Worker hands its reply to the outbox **as a draft**. The draft waits in the outbox
until the person opens it, edits it if needed, and schedules or sends it like any message.
Nothing Ticket Worker sends ever reaches Jira without that click.

## What to build

### 1. A server-to-server door

- New endpoints under `/api/v1/`, for programs rather than the browser.
- They require `Authorization: Bearer <token>`, compared in constant time against a new
  setting `OUTBOX_API_TOKEN`. With the setting empty the endpoints do not exist (404). They
  need neither the `X-Outbox` header nor the UI's Basic auth.
- Never log the token, and log draft bodies only at debug level, as with other messages.

### 2. `POST /api/v1/drafts`: hand over a draft

```json
{
  "externalId": "ticket-worker:proposal:123",
  "issueKey": "DEVBESUP-3123",
  "body": "Plain text. Paragraphs are separated by blank lines.",
  "visibility": "public",
  "source": { "name": "Ticket Worker", "url": "https://ticketworker.techfactory.dev/tickets/DEVBESUP-3123" }
}
```

- `visibility`: `public` (a reply the customer sees) or `internal` (an internal note). Only
  meaningful in a Jira Service Management space; elsewhere every comment is just a comment.
- Answer `201` with
  `{ "id", "state": "draft", "url": "https://jira.techfactory.dev/DEVBESUP-3123?draft=<id>" }`.
- **Idempotent by `externalId`.** The same `externalId` again replaces the body of that draft
  while it is still a draft (`200`, same shape). Once it is scheduled, sent or discarded,
  answer `409` with its current state and leave it alone.
- Validate the key format and that the ticket exists (one `GET /rest/api/3/issue/{key}`);
  `422` with a readable message otherwise. Body: required, at most 32 000 characters.
- A draft has **no send time**. The scheduler never picks it up.

### 3. Drafts in the outbox

- A **Drafts** list in the queue, newest first, each showing the ticket, the first lines, the
  visibility, and where it came from (`source.name`, linking to `source.url`).
- Opening `/{KEY}?draft=<id>` loads the draft into the compose form: ticket, text and
  visibility. From there it is an ordinary message: edit, add mentions or files, schedule or
  send, change status or assignee.
- **Discard** marks a draft discarded. It stays readable for a while; it is never sent.

### 4. `GET /api/v1/drafts/{id}`: what became of it

```json
{
  "id": "…", "externalId": "ticket-worker:proposal:123", "issueKey": "DEVBESUP-3123",
  "state": "draft | scheduled | sent | failed | discarded",
  "sendAt": null, "sentAt": null, "commentId": null, "commentUrl": null
}
```

Ticket Worker asks this now and then to show "scheduled for 08:00" or "sent", with a link to
the comment.

### 5. Internal notes

Post an internal note with the platform API and the service-desk property:

```json
POST /rest/api/3/issue/{key}/comment
{ "body": <ADF>, "properties": [{ "key": "sd.public.comment", "value": { "internal": true } }] }
```

A public reply is the comment as you post it today. Show the visibility in the compose form
(a public/internal switch, only for service-space tickets) and in the queue, so a public
reply is never sent by accident when an internal note was meant.

### 6. Reachable from Ticket Worker's containers

Ticket Worker calls from its own Docker containers on minas. The public address
(`jira.techfactory.dev`) sits behind Authentik, and the published port is bound to
`127.0.0.1`, so neither works from another container. Make the API reachable on an internal
Docker network shared by both projects (for example an external network `apps-internal`),
and accept that service name as a `Host` (`ALLOWED_HOSTS`). Ticket Worker then sets
`OUTBOX_URL` (for example `http://<service>:<port>`) and `OUTBOX_API_TOKEN`.

### 7. Tests

- No token, or a wrong one: 401. Setting empty: 404.
- The same `externalId` twice: one draft, updated. After scheduling: 409, unchanged.
- The scheduler never sends a draft.
- `visibility: internal` posts the `sd.public.comment` property; `public` does not.
- `GET` follows a draft through scheduled and sent to the comment id.

## Not needed now

Mentions, files, status changes and assignees through the API: the person adds those in the
outbox when reviewing. Ticket Worker may ask for them later.
