<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import DebugModeCard from '@/components/settings/DebugModeCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

defineOptions({ layout: [AppLayout, SettingsLayout] });

defineProps<{
    debugMode: boolean;
    appVersion: string;
}>();
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <DebugModeCard :enabled="debugMode" />
        <p class="text-xs text-muted-foreground">mymtgo v{{ appVersion }}</p>
    </div>
</template>
