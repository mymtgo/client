<script setup lang="ts">
import UpdateController from '@/actions/App/Http/Controllers/Archetypes/UpdateController';
import ArchetypeForm from '@/pages/archetypes/partials/ArchetypeForm.vue';
import ArchetypeLayout from '@/pages/archetypes/partials/ArchetypeLayout.vue';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
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
    archetype: App.Data.Front.ArchetypeData;
    cards: App.Data.Front.CardData[] | null;
}>();

function handleSubmit(data: { name: string; format: string; color_identity: string | null; cards: any[] }) {
    router.put(UpdateController.url({ archetype: props.archetype.id }), data);
}
</script>

<template>
    <ArchetypeLayout :archetypes="archetypes" :formats="formats" :filters="filters" :selected-id="archetype.id">
        <div class="flex min-h-0 h-full flex-col p-4">
            <div class="mb-6 border-b border-black/60 pb-4">
                <h1 class="text-lg font-bold text-foreground">Edit {{ archetype.name }}</h1>
            </div>
            <ArchetypeForm
                :name="archetype.name"
                :format="archetype.format"
                :color-identity="archetype.colorIdentity"
                :initial-cards="cards"
                submit-label="Save Changes"
                @submit="handleSubmit"
            />
        </div>
    </ArchetypeLayout>
</template>
