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
