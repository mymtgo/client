<script setup lang="ts">
import ShowController from '@/actions/App/Http/Controllers/Decks/ShowController';
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const page = usePage();

defineProps<{
    format: string;
    decks: App.Data.Front.DeckData[];
}>();

const expanded = ref<boolean>(true);
</script>

<template>
    <div>
        <button type="button" class="flex items-center gap-1 font-mono text-sm text-slate-200">
            <span>{{ format }}</span>
            <span>({{ decks.length }})</span>
        </button>

        <ul class="mt-2 ml-4.5 space-y-2" v-if="expanded">
            <Link
                :href="ShowController(deck.id).url"
                v-for="deck in decks"
                :key="`deck_${deck.id}`"
                :class="{
                    'text-white': page.props.currentDeck == deck.id,
                    'text-slate-400 hover:text-white': page.props.currentDeck != deck.id,
                }"
                class="group flex items-center gap-1 shadow"
            >
                <div class="grow truncate">
                    {{ deck.name }}
                </div>
                <div
                    class="flex shrink-0 items-center gap-2 pl-4"
                    :class="{
                        'text-slate-900': !deck.matchesWon && !deck.matchesLost,
                    }"
                >
                    <span>{{ deck.matchesWon }}-{{ deck.matchesLost }}</span>
                </div>
            </Link>
        </ul>
    </div>
</template>

<style scoped></style>
