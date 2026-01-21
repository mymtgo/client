<script setup lang="ts">
import { show } from '@/routes/decks';
import { Link } from '@inertiajs/vue3';

defineProps<{
    deck: App.Data.Front.DeckData;
}>();
</script>

<template>
    <div class="card border-base-300 bg-base-100 text-white card-sm card-border">
        <div class="card-body">
            <h2 class="card-title">{{ deck.name }}</h2>
            <p>
                <span>{{ deck.winrate }}%</span> <span>{{ deck.matchesWon }} - {{ deck.matchesLost }}</span>
            </p>
            <div class="card-actions justify-end">
                <Link prefetch :href="show({ id: deck.id }).url" class="btn btn-soft btn-primary">View</Link>
            </div>
        </div>
    </div>
</template>

<style scoped></style>
