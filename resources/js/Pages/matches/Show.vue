<script setup lang="ts">
defineProps<{
    match: App.Data.Front.MatchData;
}>();
</script>

<template>
    <div>
        <pre>
          {{ match }}
        </pre>
    </div>
</template>

<style scoped></style>
