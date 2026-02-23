<script setup lang="ts">
const props = defineProps<{
    card: App.Data.Front.CardData;
}>();

// Pixel offset per additional copy to create the stacked/fanned effect
const stackOffset = 18;

// Card images are ~745×1040px (aspect ratio ~0.717)
const cardWidth = 150;
const cardHeight = Math.round(cardWidth / 0.717);

</script>

<template>
    <div
        class="relative"
        :style="{
            width: `${cardWidth}px`,
            height: `${cardHeight + (card.quantity - 1) * stackOffset}px`,
        }"
    >
        <img
            v-for="i in card.quantity"
            :key="`qty_${i}`"
            :src="card.image ?? undefined"
            :alt="card.name ?? ''"
            class="absolute rounded-[7px] shadow-md"
            :style="{
                width: `${cardWidth}px`,
                top: `${(i - 1) * stackOffset}px`,
                zIndex: i,
            }"
        />
    </div>
</template>
