<script setup lang="ts">
import ArchetypeSidebar from '@/pages/archetypes/partials/ArchetypeSidebar.vue';

defineProps<{
    archetypes: {
        data: App.Data.Front.ArchetypeData[];
        current_page: number;
        last_page: number;
    };
    formats: Record<string, string>;
    filters: {
        format: string;
        search: string;
    };
    selectedId?: number;
}>();
</script>

<template>
    <div class="flex h-full">
        <div class="w-[35%] shrink-0">
            <ArchetypeSidebar
                :archetypes="archetypes"
                :formats="formats"
                :filters="filters"
                :selected-id="selectedId"
            />
        </div>
        <div class="flex-1 overflow-y-auto">
            <slot />
        </div>
    </div>
</template>
