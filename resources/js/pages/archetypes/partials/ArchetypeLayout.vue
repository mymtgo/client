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
    <div class="flex min-h-0 flex-1">
        <div class="w-80 shrink-0">
            <ArchetypeSidebar
                :archetypes="archetypes"
                :formats="formats"
                :filters="filters"
                :selected-id="selectedId"
            />
        </div>
        <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
            <slot />
        </div>
    </div>
</template>
