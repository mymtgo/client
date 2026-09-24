<script setup lang="ts">
import { ReplayToggleButton } from '@mymtgo/replay';
import { Share2 } from 'lucide-vue-next';
import { shallowRef } from 'vue';
import ReplayShareDialog from './ReplayShareDialog.vue';
import type { ReplayShareState } from './types';

/** The Share control in the replay's transport bar, and the dialog it opens. */
defineProps<{
    gameId: number;
    share: ReplayShareState;
}>();

const open = shallowRef(false);
</script>

<template>
    <div class="flex">
        <ReplayToggleButton title="Share this match" :active="open" :icon="Share2" @click="open = true">
            <span>Share</span>
        </ReplayToggleButton>
        <ReplayShareDialog v-model:open="open" :game-id="gameId" :share="share" />
    </div>
</template>
