<script setup lang="ts">
import { Cloud, CloudOff } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ enabled: boolean }>();

/**
 * Indicator only, never a control. Toggling sync burns the free tier's one
 * slot and puts it into a 30 day cooldown, which is far too costly to hang
 * off a badge inside a card that navigates on click. Settings owns the
 * toggling; this only reports.
 *
 * A synced deck wears the badge at all times. An unsynced one shows it on
 * hover alone: on the free tier that is every card but one, and a permanent
 * muted cloud on all of them is noise rather than a signal.
 */
const title = computed(() => (props.enabled ? 'Syncing to your MyMTGO account' : 'Not syncing. Choose which decks sync in Settings, under Account.'));
</script>

<template>
    <span
        :title="title"
        :aria-label="title"
        class="flex items-center transition-opacity"
        :class="enabled ? 'text-sky-300 opacity-100' : 'text-white/50 opacity-0 group-hover:opacity-100'"
    >
        <Cloud v-if="enabled" class="size-4" />
        <CloudOff v-else class="size-4" />
    </span>
</template>
