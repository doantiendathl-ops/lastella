import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';

// Mobile browsers restore a frozen DOM snapshot from the back-forward cache when a
// backgrounded tab/app resumes, instead of re-running app boot. Without this, the UI
// looks stuck (stale auth state, stale page) until the user manually reloads.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        window.location.reload();
    }
});

// A session/CSRF-expired request (common after a mobile tab sits backgrounded) comes
// back as a plain 419/401 HTML response, not an Inertia response. Left unhandled,
// Inertia has nothing to render and the visit silently does nothing — indistinguishable
// from the app being frozen. Force a reload so the user lands on a fresh, authenticated page.
router.on('invalid', (event) => {
    const status = event.detail.response?.status;

    if (status === 419 || status === 401) {
        event.preventDefault();
        window.location.reload();
    }
});

createInertiaApp({
    title: (title) => title ? `${title} - Lastella PMS` : 'Lastella PMS',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });

        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#196251',
    },
});
