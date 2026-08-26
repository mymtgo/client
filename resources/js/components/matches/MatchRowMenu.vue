<script setup lang="ts">
import CreateController from '@/actions/App/Http/Controllers/Archetypes/CreateController';
import DeleteController from '@/actions/App/Http/Controllers/Matches/DeleteController';
import DetectArchetypeController from '@/actions/App/Http/Controllers/Matches/DetectArchetypeController';
import UpdateArchetypeController from '@/actions/App/Http/Controllers/Matches/UpdateArchetypeController';
import ArchetypePicker from '@/components/matches/ArchetypePicker.vue';
import MatchNotesDialog from '@/components/matches/MatchNotesDialog.vue';
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
import { useToast } from '@/composables/useToast';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        matchId: number;
        format: string;
        currentArchetypeId: number | null;
        notes: string | null;
        archetypes: App.Data.Front.ArchetypeData[];
        /** Limited matches have no archetype to detect, set or clear. */
        showArchetype?: boolean;
    }>(),
    { showArchetype: true },
);

const rootOpen = ref(false);
const submenuOpen = ref(false);
const notesDialog = ref<InstanceType<typeof MatchNotesDialog> | null>(null);
const { add: toast } = useToast();

const hasArchetype = computed(() => props.currentArchetypeId !== null);

const setForm = useForm<{ archetype_id: number | null }>({ archetype_id: null });
const deleteForm = useForm<{ id: string | number }>({ id: '' });

function selectArchetype(archetypeId: number) {
    setForm.archetype_id = archetypeId;
    setForm.submit(UpdateArchetypeController({ id: props.matchId }), {
        preserveScroll: true,
        onSuccess: () => {
            setForm.reset();
            submenuOpen.value = false;
            rootOpen.value = false;
        },
    });
}

function clearArchetype() {
    setForm.archetype_id = null;
    setForm.submit(UpdateArchetypeController({ id: props.matchId }), {
        preserveScroll: true,
        onSuccess: () => {
            setForm.reset();
            rootOpen.value = false;
        },
    });
}

function detectArchetype() {
    router.post(
        DetectArchetypeController({ id: props.matchId }).url,
        {},
        {
            preserveScroll: true,
            onError: () => {
                toast({
                    type: 'error',
                    title: 'Detection failed',
                    message: "Could not determine the opponent's archetype for this match.",
                });
            },
        },
    );
    rootOpen.value = false;
}

function createFromMatch() {
    router.visit(CreateController.url({ query: { source_match_id: props.matchId } }));
    rootOpen.value = false;
}

function deleteMatch() {
    deleteForm.id = props.matchId;
    deleteForm.submit(DeleteController({ id: props.matchId }), {
        preserveScroll: true,
        onSuccess: () => deleteForm.reset(),
    });
    rootOpen.value = false;
}

function openNotes() {
    notesDialog.value?.openForMatch(props.matchId, props.notes);
    rootOpen.value = false;
}
</script>

<template>
    <ContextMenu v-model:open="rootOpen">
        <ContextMenuTrigger as-child>
            <slot />
        </ContextMenuTrigger>
        <ContextMenuContent class="w-56">
            <ContextMenuItem @select="openNotes">
                {{ notes ? 'Edit notes' : 'Add notes' }}
            </ContextMenuItem>

            <template v-if="showArchetype">
                <ContextMenuSeparator />

                <ContextMenuLabel class="text-xs tracking-wide text-muted-foreground uppercase">Archetype</ContextMenuLabel>
                <ContextMenuItem @select="detectArchetype">Detect</ContextMenuItem>

                <ContextMenuSub v-model:open="submenuOpen">
                    <ContextMenuSubTrigger>Set manually</ContextMenuSubTrigger>
                    <ContextMenuSubContent class="p-0" :align-offset="-4">
                        <ArchetypePicker
                            :archetypes="archetypes"
                            :format="format"
                            :current-archetype-id="currentArchetypeId"
                            :open="submenuOpen"
                            :disabled="setForm.processing"
                            @select="selectArchetype"
                        />
                    </ContextMenuSubContent>
                </ContextMenuSub>

                <ContextMenuItem @select="createFromMatch">Create from match</ContextMenuItem>

                <ContextMenuItem :disabled="!hasArchetype" @select="clearArchetype">Clear</ContextMenuItem>
            </template>

            <ContextMenuSeparator />

            <ContextMenuItem class="text-destructive focus:bg-destructive/10 focus:text-destructive" @select="deleteMatch">
                Remove from stats
            </ContextMenuItem>
        </ContextMenuContent>
    </ContextMenu>

    <MatchNotesDialog ref="notesDialog" />
</template>
