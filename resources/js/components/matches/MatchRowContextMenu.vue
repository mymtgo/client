<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
    ContextMenuSub,
    ContextMenuSubContent,
    ContextMenuSubTrigger,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import ArchetypePicker from '@/components/matches/ArchetypePicker.vue';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';

const props = defineProps<{
    match: App.Data.Front.MatchData;
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const emit = defineEmits<{
    detect: [matchId: number];
    delete: [matchId: number];
    openNotes: [matchId: number, notes: string | null];
}>();

const rootOpen = ref(false);
const submenuOpen = ref(false);

const currentArchetypeId = computed(() => props.match.opponentArchetypes?.[0]?.archetype?.id ?? null);

const hasArchetype = computed(() => currentArchetypeId.value !== null);

const setForm = useForm<{ archetype_id: number | null }>({
    archetype_id: null,
});

const selectArchetype = (archetypeId: number) => {
    setForm.archetype_id = archetypeId;
    setForm.submit(UpdateArchetypeController({ id: props.match.id }), {
        preserveScroll: true,
        onSuccess: () => {
            setForm.reset();
            submenuOpen.value = false;
            rootOpen.value = false;
        },
    });
};

const clearArchetype = () => {
    setForm.archetype_id = null;
    setForm.submit(UpdateArchetypeController({ id: props.match.id }), {
        preserveScroll: true,
        onSuccess: () => {
            setForm.reset();
            rootOpen.value = false;
        },
    });
};
</script>

<template>
    <ContextMenu v-model:open="rootOpen">
        <ContextMenuTrigger as-child>
            <slot />
        </ContextMenuTrigger>
        <ContextMenuContent class="w-56">
            <ContextMenuItem @select="emit('openNotes', match.id, match.notes ?? null)">
                {{ match.notes ? 'Edit notes' : 'Add notes' }}
            </ContextMenuItem>

            <ContextMenuSeparator />

            <ContextMenuLabel class="text-muted-foreground text-xs uppercase tracking-wide">
                Archetype
            </ContextMenuLabel>
            <ContextMenuItem @select="emit('detect', match.id)">Detect</ContextMenuItem>

            <ContextMenuSub v-model:open="submenuOpen">
                <ContextMenuSubTrigger>Set manually</ContextMenuSubTrigger>
                <ContextMenuSubContent class="p-0" :align-offset="-4">
                    <ArchetypePicker
                        :archetypes="archetypes"
                        :format="match.format"
                        :current-archetype-id="currentArchetypeId"
                        :open="submenuOpen"
                        :disabled="setForm.processing"
                        @select="selectArchetype"
                    />
                </ContextMenuSubContent>
            </ContextMenuSub>

            <ContextMenuItem :disabled="!hasArchetype" @select="clearArchetype">
                Clear
            </ContextMenuItem>

            <ContextMenuSeparator />

            <ContextMenuItem
                class="text-destructive focus:text-destructive focus:bg-destructive/10"
                @select="emit('delete', match.id)"
            >
                Remove from stats
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>
</template>
