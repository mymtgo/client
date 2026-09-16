<script setup lang="ts">
import ArchetypePicker from '@/components/archetypes/ArchetypePicker.vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Tags, X } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    count: number;
    archetypes: App.Data.Front.ArchetypeData[] | undefined;
    busy: boolean;
    /** Shared format of the selected decks, or null when they span formats. */
    format: string | null;
}>();

const emit = defineEmits<{
    assign: [archetypeId: number | null];
    dismiss: [];
}>();

const pickerOpen = ref(false);

function onPick(archetypeId: number) {
    pickerOpen.value = false;
    emit('assign', archetypeId);
}
</script>

<!--
    Mirrors the bulk bar above MatchesTable. A flex child of the content column
    rather than position: fixed, which would sit on top of the StatusBar and
    ignore the sidebar column.
-->
<template>
    <div v-if="count > 0" class="flex items-center gap-3 border-t border-black/60 bg-muted/50 px-4 py-2 animate-in slide-in-from-bottom-2">
        <span class="text-sm font-medium tabular-nums">{{ count }} selected</span>

        <Popover v-model:open="pickerOpen">
            <PopoverTrigger as-child>
                <Button variant="outline" size="sm" class="gap-1.5" :disabled="busy">
                    <Tags class="size-3.5" />
                    Assign archetype
                </Button>
            </PopoverTrigger>
            <PopoverContent side="top" align="start" class="w-72 p-2">
                <ArchetypePicker :archetypes="archetypes" :format="format" :show-format="format === null" autofocus @select="onPick" />
            </PopoverContent>
        </Popover>

        <Button variant="ghost" size="sm" class="gap-1.5 text-muted-foreground" :disabled="busy" @click="emit('assign', null)">
            Clear archetype
        </Button>

        <Button variant="ghost" size="sm" class="ml-auto gap-1.5 text-muted-foreground" @click="emit('dismiss')">
            <X class="size-3.5" />
            Deselect
        </Button>
    </div>
</template>
