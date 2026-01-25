<script setup lang="ts">
import { computed } from 'vue';
import { Card, CardContent } from '@/components/ui/card';
import MtgoCard from '@/components/MtgoCard.vue';

const props = defineProps<{
    content: any;
}>();

const players = computed(() => {
    return props.content.Players;
});
</script>

<template>
    <Card>
        <CardContent>
            <div class="grid grid-cols-2">
                <div v-for="player in players">
                    {{ player.Name }}
                </div>
            </div>
        </CardContent>
    </Card>
</template>

<style scoped></style>
