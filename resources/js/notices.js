import { router } from '@inertiajs/vue3';
import { reachable } from './connection.js';

// Browser notifications for work you asked for (docs/TEAM.md, item 4): once allowed, the page
// asks every 20 seconds what finished for you, and says so even with the tab in the back.
const EVERY_MS = 20000;
let since = new Date().toISOString();
let timer = null;
const seen = new Set();

export const notificationsSupported = () => typeof window !== 'undefined' && 'Notification' in window;
export const notificationsOn = () => notificationsSupported() && Notification.permission === 'granted';

async function look() {
    if (!notificationsOn() || !reachable()) {
        return;
    }
    try {
        const response = await fetch(`/notices?since=${encodeURIComponent(since)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
            return;
        }
        const data = await response.json();
        since = data.now ?? since;
        for (const notice of data.notices ?? []) {
            if (seen.has(notice.id)) {
                continue;
            }
            seen.add(notice.id);
            const shown = new Notification(notice.title, { body: notice.body, tag: notice.id, icon: '/icon-192.png' });
            shown.onclick = () => {
                window.focus();
                router.visit(notice.url);
                shown.close();
            };
        }
    } catch {
        // Offline or signed out: the connection banner says so; try again next time.
    }
}

export function watchNotices() {
    clearInterval(timer);
    timer = setInterval(look, EVERY_MS);
}

export async function askForNotifications() {
    if (!notificationsSupported()) {
        return 'unsupported';
    }
    const answer = await Notification.requestPermission();
    if (answer === 'granted') {
        watchNotices();
    }
    return answer;
}
