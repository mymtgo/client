<script setup lang="ts">
defineProps<{
    game: App.Data.Front.GameData
}>()
</script>

<template>
    <div>
        {{ game }}
    </div>
</template>

<style scoped></style>
