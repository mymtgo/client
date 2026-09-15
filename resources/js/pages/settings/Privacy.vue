<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import ApiStatusCard from '@/components/settings/ApiStatusCard.vue';
import OfflineModeCard from '@/components/settings/OfflineModeCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import type { PendingMatch } from '@/types/settings';

defineOptions({ layout: [AppLayout, SettingsLayout] });

defineProps<{
    hasArchetypeCatalog: boolean;
    offlineModeLockedUntil: string | null;
    pendingMatches: PendingMatch[];
}>();
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <OfflineModeCard :locked-until="offlineModeLockedUntil" :has-archetype-catalog="hasArchetypeCatalog" :pending-matches="pendingMatches" />
        <ApiStatusCard />
    </div>
</template>
