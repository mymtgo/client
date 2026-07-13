<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppNav from '@/components/AppNav.vue';
import HelpPopover from '@/components/HelpPopover.vue';
import StatusBar from '@/components/StatusBar.vue';
import SupportPopover from '@/components/SupportPopover.vue';
import UpdateBanner from '@/components/UpdateBanner.vue';
import ResolvedNote from './ResolvedNote.vue';
import SinkSection from './SinkSection.vue';
</script>

<template>
    <SinkSection id="shell" title="Shell">
        <div class="flex flex-col gap-1.5">
            <span class="text-xs text-muted-foreground">AppHeader</span>
            <div class="overflow-hidden rounded-md border">
                <AppHeader />
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-xs text-muted-foreground">AppNav</span>
            <div class="overflow-hidden rounded-md border">
                <AppNav />
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-xs text-muted-foreground">UpdateBanner (idle — no update available, renders nothing visible)</span>
            <div class="overflow-hidden rounded-md border p-3">
                <UpdateBanner />
            </div>
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-xs text-muted-foreground">StatusBar</span>
            <div class="overflow-hidden rounded-md border">
                <StatusBar />
            </div>
        </div>

        <ResolvedNote
            tag="decided · B"
            note="AppNav active = raised surface (panel-2) + line-2 border, foreground text. --text-background-accent (fix 01) stays defined for accent-tint uses elsewhere."
        />

        <ResolvedNote note="Get help / Support development rebuilt on the standard Popover — no bespoke chrome." />
        <div class="flex flex-wrap items-center gap-6">
            <HelpPopover />
            <SupportPopover />
        </div>
    </SinkSection>
</template>
