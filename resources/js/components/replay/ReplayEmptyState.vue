<script setup lang="ts">
import { History } from 'lucide-vue-next';
</script>

<template>
    <div class="grid flex-1 place-items-center p-6">
        <div class="flex max-w-85 flex-col items-center gap-2.5 text-center">
            <div class="grid size-11 place-items-center rounded-xl border border-border bg-card text-muted-foreground">
                <History :size="20" />
            </div>
            <div class="text-[15px] font-semibold">No frames recorded</div>
            <div class="text-pretty text-muted-foreground">This game was saved without any game states, so there is nothing to replay.</div>
        </div>
    </div>
</template>
