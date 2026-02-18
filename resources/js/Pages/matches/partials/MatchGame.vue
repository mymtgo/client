<script setup lang="ts">
import { computed } from 'vue';
import { find } from 'lodash';
import MtgoCard from '@/components/MtgoCard.vue';
import { Card, CardContent } from '@/components/ui/card';
import MatchGameTimelineEntry from '@/Pages/matches/partials/MatchGameTimelineEntry.vue';
import GameReplay from '@/Pages/games/partials/GameReplay.vue';
import { Link } from '@inertiajs/vue3';
import ShowController from '@/actions/App/Http/Controllers/Games/ShowController';

const props = defineProps<{
    game: App.Data.Front.GameData;
}>();

const localPlayer = computed(() => {
    return find(props.game.players, (player) => player.isLocal);
});

const opponent = computed(() => {
    return find(props.game.players, (player) => !player.isLocal);
});
</script>

<template>
    <div>
        <Card>
            <CardContent>
                <div class="grid grid-cols-8">
                    <div v-for="deckCard in opponent.deck" :key="`card_${deckCard.mtgo_id}`">
                        <MtgoCard :id="deckCard.mtgo_id">
                            <template #default="{ card }">
                                <img :src="card.image" v-if="card" class="rounded-lg" />
                            </template>
                        </MtgoCard>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div>
            <Link :href="ShowController({id: game.id}).url">Replay</Link>
        </div>
    </div>
</template>
