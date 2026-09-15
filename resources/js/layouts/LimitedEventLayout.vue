<script setup lang="ts">
import LimitedSidebar from '@/components/limited/LimitedSidebar.vue';

defineProps<{
    event: App.Data.Front.LimitedEventData;
    currentPage: string;
}>();
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="flex min-h-0 flex-1">
            <div class="w-56 shrink-0">
                <LimitedSidebar :event="event" :current-page="currentPage" />
            </div>
            <div class="flex min-h-0 flex-1 flex-col overflow-y-auto border-l border-white/5">
                <slot />
            </div>
        </div>
    </div>
</template>
