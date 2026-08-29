const themeStorageKey = 'memoria-theme';

const preferredTheme = () => localStorage.getItem(themeStorageKey) || 'system';

const resolvedTheme = (preference = preferredTheme()) => {
    if (preference === 'system') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    return preference;
};

const applyTheme = (preference = preferredTheme()) => {
    document.documentElement.classList.toggle('dark', resolvedTheme(preference) === 'dark');
    document.documentElement.dataset.theme = preference;

    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.themeChoice === preference));
    });

    document.querySelectorAll('[data-theme-label]').forEach((label) => {
        label.textContent = resolvedTheme(preference) === 'dark' ? 'Use light appearance' : 'Use dark appearance';
    });
};

const bindPublicInteractions = () => {
    applyTheme();

    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
        button.addEventListener('click', () => {
            const preference = button.dataset.themeChoice;
            localStorage.setItem(themeStorageKey, preference);
            applyTheme(preference);
        });
    });

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const preference = resolvedTheme() === 'dark' ? 'light' : 'dark';
            localStorage.setItem(themeStorageKey, preference);
            applyTheme(preference);
        });
    });

    document.querySelectorAll('[data-copy-url]').forEach((button) => {
        button.addEventListener('click', async () => {
            await navigator.clipboard.writeText(button.dataset.copyUrl || window.location.href);
            const originalLabel = button.textContent;
            button.textContent = 'Link copied';
            window.setTimeout(() => {
                button.textContent = originalLabel;
            }, 1800);
        });
    });
};

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (preferredTheme() === 'system') {
        applyTheme('system');
    }
});

document.addEventListener('DOMContentLoaded', bindPublicInteractions);
document.addEventListener('livewire:navigated', bindPublicInteractions);

applyTheme();
