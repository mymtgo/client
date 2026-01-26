<script setup lang="ts">
import { computed } from 'vue';
import type { GameTimelineEvent } from './GameReplay.vue';
import MtgoCard from '@/components/MtgoCard.vue';
import { filter, find } from 'lodash';
import { map } from 'lodash-es';

const props = defineProps<{
    event: GameTimelineEvent | null;
}>();

const hasContent = computed(() => {
    if (!props.event) return false;
    return Array.isArray(props.event.content) && props.event.content.length > 0;
});

const contentJson = computed(() => {
    if (!hasContent.value) return '';
    try {
        return JSON.stringify(props.event?.content, null, 2);
    } catch {
        return '[Invalid content]';
    }
});

const player = computed(() => {
    return find(props.event?.content.Players, (player: any) => player.IsLocal);
});
const opponent = computed(() => {
    return find(props.event?.content.Players, (player: any) => !player.IsLocal);
});

const playerBattlefieldCards = computed(() => {
    return filter(props.event?.content.Cards || [], (card) => card.Zone === 'Battlefield' && card.Controller == player.value.Id);
});

const stack = computed(() => {
    return filter(props.event?.content.Cards || [], (card) => card.Zone === 'Stack');
});

const playerGraveyard = computed(() => {
    return filter(props.event?.content.Cards || [], (card) => card.Zone === 'Graveyard' && card.Owner == player.value.Id);
});

const opponentGraveyard = computed(() => {
    return filter(props.event?.content.Cards || [], (card) => card.Zone === 'Graveyard' && card.Owner != player.value.Id);
});

const opponentBattlefieldCards = computed(() => {
    return filter(props.event?.content.Cards || [], (card) => card.Zone === 'Battlefield' && card.Controller != player.value.Id);
});

const playerHand = computed(() => {
    return filter(props.event?.content.Cards || [], (card) => card.Zone === 'Hand' && card.Owner == player.value.Id);
});

const opponentHand = computed(() => {
    return filter(props.event?.content.Cards || [], (card) => card.Zone === 'Hand' && card.Owner != player.value.Id);
});
</script>

<template>
    <div class="rounded-lg border bg-card">
        <div class="flex">
            <div class="border-r">
                <div>
                    {{ opponent.Name }} / {{ opponent.Life }} {{ opponent.HandCount }} / {{ opponent.LibraryCount }}
                    <div>
                        <div v-for="card in opponentGraveyard">
                            <img :src="card.image" class="rounded-[8px] w-24" />
                        </div>
                    </div>
                </div>
                <div>
                    {{ player.Name }} / {{ player.Life }} {{ player.HandCount }} / {{ player.LibraryCount }}
                    <div>
                        <div v-for="card in playerGraveyard">
                            <img :src="card.image" class="rounded-[8px] w-24" />
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <div>
                    <div class="flex gap-1">
                        <div v-for="card in opponentHand">
                            <img :src="card.image" class="rounded-[8px] w-24" />
                        </div>
                    </div>
                </div>

                <div>
                    <div>
                        <div class="grid grid-cols-12">
                            <div v-for="card in opponentBattlefieldCards">
                                <img :src="card.image" class="rounded-md w-24" />
                            </div>
                        </div>
                    </div>
                    <div class="border">
                        <div v-for="card in stack">
                            <img :src="card.image" class="rounded-[8px] w-24" />
                        </div>
                    </div>
                    <div>
                        <div class="flex">
                            <div v-for="card in playerBattlefieldCards">
                                <img :src="card.image" class="rounded-md w-24" />
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="flex gap-1">
                        <div v-for="card in playerHand">
                            <img :src="card.image" class="rounded-md w-24" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- No event -->
        <div v-if="!event" class="p-8 text-center text-muted-foreground">No event selected.</div>
    </div>
</template>
