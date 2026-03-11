<script setup lang="ts">
import ArchetypeLayout from '@/pages/archetypes/partials/ArchetypeLayout.vue';

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
}>();
</script>

<template>
    <ArchetypeLayout :archetypes="archetypes" :formats="formats" :filters="filters">
        <div class="flex h-full items-center justify-center">
            <p class="text-sm text-muted-foreground">Select an archetype to view details</p>
        </div>
    </ArchetypeLayout>
</template>
