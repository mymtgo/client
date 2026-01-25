<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { find } from 'lodash';

const page = usePage<{
    cards: App.Data.Front.CardData[]
}>()

const props = defineProps<{
    id: string|number
}>()

const card = computed(() => {
    return find(page.props.cards, (card) => card.mtgoId == props.id)
})
</script>

<template>
    <slot :card="card"></slot>
</template>

