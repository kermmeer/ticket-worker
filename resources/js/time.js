const units = [
    ['year', 31536000],
    ['month', 2592000],
    ['week', 604800],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
];

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/** "3 days ago", "in 2 hours", "just now". */
export function ago(iso) {
    if (!iso) {
        return '';
    }
    const seconds = (new Date(iso).getTime() - Date.now()) / 1000;
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }
    return 'just now';
}

/** The full date and time, for when "3 days ago" is not enough. */
export function stamp(iso) {
    return iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/** "30 Sep, 10:00": a date and time short enough for a list row. */
export function short(iso) {
    return iso ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
}

/** "84.2k", "1.3M": token counts short enough for a line of text. */
export function tokens(count) {
    if (count == null) {
        return '';
    }
    if (count >= 1e6) {
        return `${(count / 1e6).toFixed(1)}M`;
    }
    return count >= 1000 ? `${(count / 1000).toFixed(1)}k` : String(count);
}
