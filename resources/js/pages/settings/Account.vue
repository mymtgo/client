<script setup lang="ts">
import AppLayout from '@/AppLayout.vue';
import type { DeckSyncRow } from '@/components/settings/DeckSyncList.vue';
import DeckSyncList from '@/components/settings/DeckSyncList.vue';
import SyncCard from '@/components/settings/SyncCard.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { ref, watch } from 'vue';

defineOptions({ layout: [AppLayout, SettingsLayout] });

const props = defineProps<{
    decks: DeckSyncRow[];
    slots: { limit: number | null; used: number };
    linked: boolean;
}>();

// Seeded from the server so the list renders its real state on first paint,
// then handed over to the card above, which polls sign-in status and reports
// a link the moment it completes.
const linked = ref(props.linked);
watch(
    () => props.linked,
    (value) => (linked.value = value),
);
</script>

<template>
    <div class="flex flex-col divide-y divide-border">
        <SyncCard @update:linked="linked = $event" />
        <DeckSyncList :decks="decks" :slots="slots" :linked="linked" />
    </div>
</template>
