import { ref } from 'vue';

/**
 * Day, Night, or whatever the device says. Stored as light/dark/system in localStorage,
 * per browser; the inline script in app.blade.php applies it before first paint, and this
 * keeps it applied afterwards, including when the device switches while on "system".
 */
export const themes = [
    { value: 'system', label: 'System' },
    { value: 'light', label: 'Day' },
    { value: 'dark', label: 'Night' },
];

const values = themes.map((t) => t.value);
const media = window.matchMedia('(prefers-color-scheme: dark)');

function saved() {
    try {
        const value = localStorage.getItem('theme');
        return values.includes(value) ? value : 'system';
    } catch {
        return 'system';
    }
}

export const theme = ref(saved());

function apply() {
    const dark = theme.value === 'dark' || (theme.value === 'system' && media.matches);
    document.documentElement.classList.toggle('dark', dark);
}

media.addEventListener('change', apply);

export function setTheme(value) {
    theme.value = value;
    try {
        if (value === 'system') {
            localStorage.removeItem('theme');
        } else {
            localStorage.setItem('theme', value);
        }
    } catch {
        // Private mode or blocked storage: the choice still holds for this page.
    }
    apply();
}
