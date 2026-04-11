import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import AppLayout from './AppLayout.vue';
import '../css/app.css';
import * as Sentry from "@sentry/vue";

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = await resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue'));
        page.default.layout = page.default.layout ?? AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {

        Sentry.init({
            dsn: 'https://013633bd183642005b90b1b6ddba00a4@o4510380004802560.ingest.de.sentry.io/4511202597666896',
            integrations: [
                Sentry.feedbackIntegration({
                    // Additional SDK configuration goes in here, for example:
                    colorScheme: 'system',
                }),
            ],
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

