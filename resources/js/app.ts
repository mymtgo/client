import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import AppLayout from './AppLayout.vue';
import '../css/app.css';
import * as Sentry from "@sentry/vue";

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// The bundled Laravel server is briefly unreachable during startup/shutdown/restart.
// Swallow those XHR exceptions so polling pages don't flood Sentry or hang the renderer.
// Inertia 3 renamed this event from `exception` to `networkError`.
router.on('networkError', (event) => {
    event.preventDefault();
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = await resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue'));
        page.default.layout = page.default.layout ?? AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {



        const app = createApp({ render: () => h(App, props) })
            .use(plugin);

        if (import.meta.env.PROD) {
            Sentry.init({
                app: app,
                dsn: 'https://013633bd183642005b90b1b6ddba00a4@o4510380004802560.ingest.de.sentry.io/4511202597666896',
                integrations: [],
                // Inertia 3 ships its own XHR client (axios is gone) and rejects
                // transport failures as HttpNetworkError("Network error"). Those are
                // swallowed by the `networkError` handler above; this is a backstop.
                ignoreErrors: [
                    /Network error/i,
                    'HttpNetworkError',
                    /Failed to fetch dynamically imported module/,
                ],
            });
        }

        app.mount(el);

    },
    progress: {
        color: '#4B5563',
    },
    defaults: {
        prefetch: {
            // Inertia fires hover prefetches after 75ms by default. Deck navigation
            // and match rows are dense vertical lists, so a mouse sweep across them
            // was issuing a full page request per link. Wait for an actual dwell.
            hoverDelay: 200,
        },
    },
});

