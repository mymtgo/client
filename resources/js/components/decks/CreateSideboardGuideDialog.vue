<script setup lang="ts">
import StoreController from '@/actions/App/Http/Controllers/Decks/SideboardGuides/StoreController';
import ManaSymbols from '@/components/ManaSymbols.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useArchetypeSplit } from '@/composables/useArchetypeSplit';
import { useForm } from '@inertiajs/vue3';
import { ref, toRef } from 'vue';

/**
 * Pick an archetype to start a guide for. The server rejects a pair that
 * already has a guide; that error is shown inline and the dialog stays open so
 * another archetype can be chosen.
 */
const props = defineProps<{
    deckId: number;
    format: string | null;
    archetypes: App.Data.Front.ArchetypeData[];
}>();

const isOpen = ref(false);
const search = ref('');

const { fallbacks, regular } = useArchetypeSplit(toRef(props, 'archetypes'), toRef(props, 'format'), search);

const form = useForm<{ archetype_id: number | null }>({ archetype_id: null });

function open(): void {
    form.reset();
    form.clearErrors();
    search.value = '';
    isOpen.value = true;
}

function select(archetypeId: number): void {
    form.archetype_id = archetypeId;
    form.post(StoreController.url({ deck: props.deckId }), {
        onSuccess: () => {
            isOpen.value = false;
        },
    });
}

defineExpose({ open });
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="flex max-h-[80vh] flex-col">
            <DialogHeader>
                <DialogTitle>Create sideboard guide</DialogTitle>
                <DialogDescription>Choose the opponent archetype this guide is for.</DialogDescription>
            </DialogHeader>

            <Input v-model="search" placeholder="Search archetypes..." />

            <p v-if="form.errors.archetype_id" class="rounded-md border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-300">
                {{ form.errors.archetype_id }}
            </p>

            <div class="flex-1 space-y-0.5 overflow-y-auto">
                <template v-if="fallbacks.length">
                    <Button
                        v-for="archetype in fallbacks"
                        :key="archetype.id"
                        variant="ghost"
                        class="w-full justify-between text-muted-foreground italic"
                        :disabled="form.processing"
                        @click="select(archetype.id)"
                    >
                        <span class="flex-1 text-left">{{ archetype.name }}</span>
                        <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] tracking-wide uppercase">System</span>
                    </Button>
                    <div class="my-1 border-t border-border" />
                </template>

                <Button
                    v-for="archetype in regular"
                    :key="archetype.id"
                    variant="ghost"
                    class="w-full justify-between"
                    :disabled="form.processing"
                    @click="select(archetype.id)"
                >
                    <span class="flex-1 text-left">{{ archetype.name }}</span>
                    <ManaSymbols :symbols="archetype.colorIdentity" />
                </Button>

                <p v-if="fallbacks.length === 0 && regular.length === 0" class="py-4 text-center text-sm text-muted-foreground">
                    <template v-if="archetypes.length === 0">Loading archetypes…</template>
                    <template v-else>No archetypes found.</template>
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
