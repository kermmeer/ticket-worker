// How a ticket's SLAs read in the overview. Times are Jira's own, in the SLA's calendar
// (its working hours), as of the last sync; the due moment is a real date.

/** "46h 41m", "45m", "213h": the way Jira writes SLA time. */
export function hours(ms) {
    const total = Math.floor(Math.abs(ms) / 60000);
    const h = Math.floor(total / 60);
    const m = total % 60;
    if (h === 0) {
        return `${m}m`;
    }
    return h >= 100 || m === 0 ? `${h}h` : `${h}h ${m}m`;
}

/** "First response", "Resolution": Jira's SLA names without "Time to". */
export function shortName(name) {
    const short = name.replace(/^time to\s+/i, '');
    return short.charAt(0).toUpperCase() + short.slice(1);
}

/** The words, never just a colour: left, overdue, paused, met, missed. */
export function phrase(sla) {
    switch (sla.state) {
        case 'breached':
            return `overdue ${hours(sla.remaining_ms ?? 0)}${sla.paused ? ', paused' : ''}`;
        case 'paused':
            return `paused, ${hours(sla.remaining_ms ?? 0)} left`;
        case 'met':
            return 'met';
        case 'missed':
            return 'missed';
        default:
            return `${hours(sla.remaining_ms ?? 0)} left`;
    }
}

/** A tone from app.css: rose past due, amber in the last quarter or the last 2 hours. */
export function tone(sla) {
    if (sla.state === 'breached' || sla.state === 'missed') {
        return 'rose';
    }
    if (sla.state === 'paused') {
        return 'grey';
    }
    if (sla.state === 'met') {
        return 'green';
    }
    const left = sla.remaining_ms ?? 0;
    const tight = left < 2 * 3600000 || (sla.goal_ms && left < sla.goal_ms / 4);
    return tight ? 'amber' : 'green';
}

/** For sorting: the running SLA closest to breaching, or furthest past it. */
export function urgency(ticket) {
    const running = (ticket.slas ?? []).filter((sla) => sla.state === 'running' || sla.state === 'breached');
    return running.length ? Math.min(...running.map((sla) => sla.remaining_ms ?? Infinity)) : Infinity;
}

/**
 * When the ticket's next SLA runs out, as a timestamp: the soonest running one that is
 * not yet overdue. Null when none is running in time; overdue ones do not count, since
 * the point is the deadline you can still make.
 */
export function dueAt(ticket, now = Date.now()) {
    const running = (ticket.slas ?? []).filter((sla) => sla.state === 'running');
    const times = running.map((sla) => (sla.due_at ? Date.parse(sla.due_at) : now + (sla.remaining_ms ?? Infinity)));
    return times.length ? Math.min(...times) : null;
}

/**
 * For "Due next": still in time first, the closest deadline on top; then paused ones,
 * least time left first; then tickets without a live SLA; overdue only, last.
 */
export function dueNextRank(ticket, now = Date.now()) {
    const slas = ticket.slas ?? [];
    const due = dueAt(ticket, now);
    if (due !== null) {
        return [0, due];
    }
    const paused = slas.filter((sla) => sla.state === 'paused');
    if (paused.length) {
        return [1, Math.min(...paused.map((sla) => sla.remaining_ms ?? Infinity))];
    }
    const overdue = slas.filter((sla) => sla.state === 'breached');
    if (overdue.length) {
        // The least overdue first: still the cheapest to put right.
        return [3, -Math.max(...overdue.map((sla) => sla.remaining_ms ?? -Infinity))];
    }
    return [2, 0];
}

export function byDueNext(a, b, now = Date.now()) {
    const [ta, va] = dueNextRank(a, now);
    const [tb, vb] = dueNextRank(b, now);
    return ta - tb || (va === vb ? 0 : va < vb ? -1 : 1);
}
