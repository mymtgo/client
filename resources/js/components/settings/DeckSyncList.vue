<script setup lang="ts">
import UpdateCloudSyncController from '@/actions/App/Http/Controllers/Decks/UpdateCloudSyncController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatDay } from '@/lib/utils';
import { router, usePage } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

export type DeckSyncRow = {
    id: number;
    name: string;
    format: string;
    archetype: string | null;
    lastPlayedAtHuman: string | null;
    cloudSyncEnabled: boolean;
    freesAt: string | null;
};

const props = defineProps<{
    decks: DeckSyncRow[];
    slots: { limit: number | null; used: number };
    /** Mirrors the account card above, which polls it, so linking updates both. */
    linked: boolean;
}>();

const page = usePage();
const search = ref('');
const savingId = ref<number | null>(null);

// The last deck toggled, so the shared `cloud_sync` error lands under the row
// that caused it rather than floating at the foot of the list.
const lastToggledId = ref<number | null>(null);

// Toggling is optimistic: the switch moves at once and the server's answer
// arrives as a prop refresh. A rejected toggle rolls back through `decks`.
const optimistic = ref<Record<number, boolean>>({});

const error = computed(() => (page.props.errors as Record<string, string> | undefined)?.cloud_sync ?? null);

const slotsFull = computed(() => props.slots.limit !== null && props.slots.used >= props.slots.limit);

// Long lists need the filter; a handful of decks are quicker to read whole.
const searchable = computed(() => props.decks.length > 8);

const visibleDecks = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return props.decks;
    }

    return props.decks.filter((deck) => [deck.name, deck.format, deck.archetype ?? ''].some((field) => field.toLowerCase().includes(term)));
});

function isOn(deck: DeckSyncRow): boolean {
    return optimistic.value[deck.id] ?? deck.cloudSyncEnabled;
}

/**
 * A deck already holding a slot can always be turned off, and one in cooldown
 * can be turned back on for free, so only a fresh deck is blocked by a full
 * account.
 */
function blockedReason(deck: DeckSyncRow): string | null {
    if (!props.linked) {
        return 'Sign in above to sync decks.';
    }

    if (isOn(deck) || deck.freesAt !== null) {
        return null;
    }

    if (slotsFull.value) {
        return props.slots.limit === 1 ? 'Your slot is in use. Turn another deck off first.' : 'Every slot is in use. Turn another deck off first.';
    }

    return null;
}

function toggle(deck: DeckSyncRow, value: boolean) {
    optimistic.value = { ...optimistic.value, [deck.id]: value };
    savingId.value = deck.id;
    lastToggledId.value = deck.id;

    router.patch(
        UpdateCloudSyncController.url(deck.id),
        { enabled: value },
        {
            preserveScroll: true,
            onFinish: () => {
                optimistic.value = {};
                savingId.value = null;
            },
        },
    );
}
</script>

<template>
    <SettingsSection
        title="Deck sync"
        description="Pick the decks whose matches and leagues are backed up to your account. Turning one off keeps its data in the cloud and holds its slot for 30 days."
    >
        <div class="flex items-center justify-between gap-4">
            <span class="text-sm text-muted-foreground">Deck slots</span>
            <span class="text-sm tabular-nums">
                <template v-if="slots.limit === null">{{ slots.used }} in use, no limit</template>
                <template v-else>{{ slots.used }} of {{ slots.limit }} used</template>
            </span>
        </div>

        <div v-if="searchable" class="relative">
            <Search class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input v-model="search" placeholder="Search decks..." class="h-8 pl-7 text-xs" />
        </div>

        <p v-if="decks.length === 0" class="text-sm text-muted-foreground">No decks yet. Play a match on MTGO and your decks land here.</p>

        <p v-else-if="visibleDecks.length === 0" class="text-sm text-muted-foreground">No deck matches "{{ search }}".</p>

        <div v-else class="max-h-80 divide-y divide-border overflow-auto rounded-lg border border-border bg-background">
            <div v-for="deck in visibleDecks" :key="deck.id" class="flex flex-col gap-1 px-3 py-2.5">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex min-w-0 flex-col">
                        <span class="truncate text-sm font-medium">{{ deck.name }}</span>
                        <span class="truncate text-xs text-muted-foreground">
                            {{ deck.format }}
                            <template v-if="deck.archetype"> · {{ deck.archetype }}</template>
                            <template v-if="deck.lastPlayedAtHuman"> · {{ deck.lastPlayedAtHuman }}</template>
                        </span>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Spinner v-if="savingId === deck.id" class="size-3.5 text-muted-foreground" />
                        <Switch
                            :model-value="isOn(deck)"
                            :disabled="blockedReason(deck) !== null || savingId !== null"
                            :title="blockedReason(deck) ?? undefined"
                            :aria-label="`Sync ${deck.name}`"
                            @update:model-value="(value: boolean) => toggle(deck, value)"
                        />
                    </div>
                </div>

                <p v-if="deck.freesAt && !isOn(deck)" class="text-xs text-warning">
                    Slot held until {{ formatDay(deck.freesAt) }}. Turning this deck back on before then is free.
                </p>
                <p v-if="error && lastToggledId === deck.id" class="text-xs text-destructive">{{ error }}</p>
            </div>
        </div>

        <p v-if="slots.limit !== null" class="text-xs text-muted-foreground">
            The free plan syncs {{ slots.limit }} constructed deck{{ slots.limit === 1 ? '' : 's' }} at a time. Supporter lifts the limit and adds
            draft and sealed decks.
        </p>
    </SettingsSection>
</template>
