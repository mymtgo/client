<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';
import ArchetypeDetail from '@/pages/archetypes/partials/ArchetypeDetail.vue';
import ArchetypeLayout from '@/pages/archetypes/partials/ArchetypeLayout.vue';
import { Deferred } from '@inertiajs/vue3';

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
    detail?: App.Data.Front.ArchetypeDetailData;
}>();
</script>

<template>
    <ArchetypeLayout
        :archetypes="archetypes"
        :formats="formats"
        :filters="filters"
        :selected-id="detail?.archetype.id"
    >
        <Deferred data="detail">
            <template #fallback>
                <div class="flex h-full items-center justify-center">
                    <Spinner class="size-5" />
                </div>
            </template>
            <ArchetypeDetail v-if="detail" :detail="detail" />
        </Deferred>
    </ArchetypeLayout>
</template>
