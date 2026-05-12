<script setup lang="ts">
import StoreController from '@/actions/App/Http/Controllers/Archetypes/StoreController';
import ArchetypeForm from '@/pages/archetypes/partials/ArchetypeForm.vue';
import ArchetypeLayout from '@/pages/archetypes/partials/ArchetypeLayout.vue';
import { router } from '@inertiajs/vue3';

interface MatchOption {
    id: number;
    opponent_username: string;
    started_at: string | null;
}

interface Prefill {
    source_match_id: number;
    format: string;
    color_identity: string | null;
    cards: any[];
}

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
    matches: MatchOption[];
    prefill: Prefill | null;
}>();

function handleSubmit(data: { name: string; format: string; color_identity: string | null; cards: any[] | null; source_match_id: number | null; incomplete: boolean }) {
    router.post(StoreController.url(), data);
}
</script>

<template>
    <ArchetypeLayout :archetypes="archetypes" :formats="formats" :filters="filters">
        <div class="flex min-h-0 h-full flex-col p-4">
            <div class="mb-6 border-b border-black/60 pb-4">
                <h1 class="text-lg font-bold text-foreground">Create Archetype</h1>
            </div>
            <ArchetypeForm :matches="matches" :prefill="prefill" submit-label="Create Archetype" require-cards @submit="handleSubmit" />
        </div>
    </ArchetypeLayout>
</template>
