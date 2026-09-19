<script setup lang="ts">
import SettingsSideNav from '@/components/settings/SettingsSideNav.vue';
import type { SettingsCurrentPage } from '@/types/settings';
import { computed } from 'vue';

const props = defineProps<{
    currentPage: SettingsCurrentPage;
}>();

const headings: Record<SettingsCurrentPage, { title: string; description: string }> = {
    general: { title: 'General', description: 'Accounts and how mymtgo runs on your machine.' },
    account: { title: 'Account', description: 'Back your matches up to MyMTGO and keep them in sync across your devices.' },
    overlays: { title: 'Overlays', description: 'Always-on-top windows shown during leagues, matches and drafts.' },
    storage: { title: 'Storage', description: 'Where MTGO files live and what mymtgo keeps on disk.' },
    privacy: { title: 'Data & Privacy', description: 'What leaves this device and what comes back in.' },
    advanced: { title: 'Advanced', description: 'Developer tools and diagnostics.' },
};

const heading = computed(() => headings[props.currentPage]);
</script>

<template>
    <div class="flex min-h-0 flex-1">
        <div class="w-56 shrink-0">
            <SettingsSideNav :current-page="currentPage" />
        </div>
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto border-l border-white/5">
            <header class="border-b border-black/60 bg-background/40 px-6 py-4">
                <h1 class="text-lg font-semibold text-foreground">{{ heading.title }}</h1>
                <p class="text-sm text-muted-foreground">{{ heading.description }}</p>
            </header>
            <div class="flex-1 overflow-auto">
                <div class="max-w-3xl p-6">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
