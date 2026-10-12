import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';

// What the page knows about reaching the app. When a sign-in in front of it (Authentik)
// expires, every background request is redirected to its login page, which the browser
// blocks for a request that is not a page load: all the page sees is a "network error".
// So on one, ask /up without following redirects: a redirect means the sign-in expired,
// no answer at all means the connection is gone.
export const connection = reactive({ signedOut: false, offline: false, problem: null });

let checking = false;

async function check() {
    if (checking) {
        return;
    }
    checking = true;
    try {
        const response = await fetch('/up', { redirect: 'manual', cache: 'no-store', credentials: 'same-origin' });
        connection.signedOut = response.type === 'opaqueredirect';
        connection.offline = false;
    } catch {
        connection.offline = true;
    } finally {
        checking = false;
    }
}

/** Background refreshes skip their turn while nothing can get through. */
export const reachable = () => !connection.signedOut && !connection.offline;

let clearProblem = null;

export function watchConnection() {
    // An action the server refused or that broke: say so at the top of the page, instead of
    // Inertia's modal over everything, which made the page look stuck until a refresh.
    router.on('httpException', (event) => {
        event.preventDefault();
        const response = event.detail.response;
        if (response.status === 419) {
            window.location.reload();
            return;
        }
        let data = response.data;
        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch {
                data = null;
            }
        }
        connection.problem = data?.message ?? `That did not work (${response.status}).`;
        clearTimeout(clearProblem);
        clearProblem = setTimeout(() => (connection.problem = null), 12000);
    });

    router.on('networkError', (event) => {
        // Handled here, with a banner, instead of an uncaught error in the console.
        event.preventDefault();
        check();
    });
    router.on('success', () => {
        connection.signedOut = false;
        connection.offline = false;
    });
    // Offline: try again now and then, so the banner goes away by itself.
    setInterval(() => connection.offline && check(), 15000);
}

/** A full page load: the one request a sign-in page in front of the app can answer. */
export function signInAgain() {
    window.location.reload();
}
