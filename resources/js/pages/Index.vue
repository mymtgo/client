<script setup lang="ts">
import SegmentedControl from '@/components/SegmentedControl.vue';
import ToastContainer from '@/components/ToastContainer.vue';
import { useAppearance, type Appearance } from '@/composables/useAppearance';
import SinkButtonsSection from './partials/sink/SinkButtonsSection.vue';
import SinkDataSection from './partials/sink/SinkDataSection.vue';
import SinkFeedbackSection from './partials/sink/SinkFeedbackSection.vue';
import SinkFormsSection from './partials/sink/SinkFormsSection.vue';
import SinkMtgoSection from './partials/sink/SinkMtgoSection.vue';
import SinkOverlaysSection from './partials/sink/SinkOverlaysSection.vue';
import SinkShellSection from './partials/sink/SinkShellSection.vue';
import SinkThemeSection from './partials/sink/SinkThemeSection.vue';

const { appearance, updateAppearance } = useAppearance();

const sections = [
    { id: 'theme', label: 'Theme' },
    { id: 'buttons', label: 'Buttons' },
    { id: 'forms', label: 'Forms' },
    { id: 'overlays', label: 'Overlays' },
    { id: 'data', label: 'Data' },
    { id: 'mtgo', label: 'MTGO' },
    { id: 'feedback', label: 'Feedback' },
    { id: 'shell', label: 'Shell' },
];
</script>

<template>
    <div class="min-h-screen">
        <header class="sticky top-0 z-40 flex items-center gap-4 border-b bg-background/95 px-6 py-3 backdrop-blur">
            <h1 class="text-base font-semibold tracking-tight">Kitchen sink</h1>
            <nav class="flex flex-1 items-center gap-3 overflow-x-auto text-sm text-muted-foreground">
                <a v-for="s in sections" :key="s.id" :href="`#${s.id}`" class="hover:text-foreground">{{ s.label }}</a>
            </nav>
            <SegmentedControl
                :model-value="appearance"
                :options="[
                    { label: 'Light', value: 'light' },
                    { label: 'Dark', value: 'dark' },
                    { label: 'System', value: 'system' },
                ]"
                @update:model-value="updateAppearance($event as Appearance)"
            />
        </header>
        <main class="flex flex-col gap-12 px-6 py-8">
            <SinkThemeSection />
            <SinkButtonsSection />
            <SinkFormsSection />
            <SinkOverlaysSection />
            <SinkDataSection />
            <SinkMtgoSection />
            <SinkFeedbackSection />
            <SinkShellSection />
        </main>
        <ToastContainer />
    </div>
</template>
