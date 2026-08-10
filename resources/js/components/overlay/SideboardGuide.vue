<script setup lang="ts">
import ArchetypeNotes from '@/components/overlay/ArchetypeNotes.vue';
import ManaSymbols from '@/components/ManaSymbols.vue';

const props = defineProps<{
    sideboard: App.Data.Front.SideboardGuideData | null;
    notes: { current: App.Data.Front.ArchetypeNoteData[]; other: App.Data.Front.ArchetypeNoteData[] };
    hasMatch: boolean;
    hasDeck: boolean;
    hasArchetype: boolean;
}>();
</script>

<template>
    <div class="flex flex-col gap-3 p-3">
        <p v-if="!props.hasMatch" class="text-center text-xs text-muted-foreground">Waiting for match…</p>
        <p v-else-if="!props.hasDeck" class="text-center text-xs text-muted-foreground">No deck linked to this match</p>
        <p v-else-if="!props.hasArchetype" class="text-center text-xs text-muted-foreground">
            Pick an archetype to see your sideboard guide
        </p>

        <template v-else-if="props.sideboard">
            <p class="text-[10px] text-muted-foreground">
                <template v-if="props.sideboard.postboardGames > 0">
                    {{ props.sideboard.postboardGames }} post-board
                    {{ props.sideboard.postboardGames === 1 ? 'game' : 'games' }} ·
                    {{ props.sideboard.postboardRecord }} overall
                </template>
                <template v-else>No games vs this archetype yet</template>
            </p>

            <section class="flex flex-col gap-1">
                <h3 class="text-xs font-semibold">Sideboard</h3>
                <div
                    v-for="card in props.sideboard.sidedIn"
                    :key="card.oracleId"
                    class="flex items-center justify-between gap-2 text-xs"
                    :class="card.sidedInGames === 0 ? 'text-muted-foreground/60' : ''"
                >
                    <span class="flex min-w-0 items-center gap-1">
                        <span class="font-semibold">{{ card.quantity }}</span>
                        <span class="truncate">{{ card.name }}</span>
                        <ManaSymbols v-if="card.colorIdentity" :symbols="card.colorIdentity" class="shrink-0" />
                    </span>
                    <span class="shrink-0 tabular-nums">
                        <template v-if="card.sidedInGames > 0">
                            {{ card.wins }}–{{ card.losses }} ({{ card.winrate }}%)
                        </template>
                        <template v-else>—</template>
                    </span>
                </div>
            </section>

            <section v-if="props.sideboard.sidedOut.length" class="flex flex-col gap-1">
                <h3 class="text-xs font-semibold">Usually cut</h3>
                <div
                    v-for="card in props.sideboard.sidedOut"
                    :key="card.oracleId"
                    class="flex items-center justify-between gap-2 text-xs"
                >
                    <span class="truncate">{{ card.name }}</span>
                    <span class="shrink-0 tabular-nums text-muted-foreground">
                        {{ card.sidedOutGames }}× out
                    </span>
                </div>
            </section>
        </template>

        <ArchetypeNotes
            :current="props.notes.current"
            :other="props.notes.other"
            :disabled="!props.hasDeck || !props.hasArchetype"
        />
    </div>
</template>
