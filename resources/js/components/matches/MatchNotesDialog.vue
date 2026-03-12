<script setup lang="ts">
import UpdateNotesController from '@/actions/App/Http/Controllers/Matches/UpdateNotesController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const open = ref(false);
const matchId = ref<number | null>(null);

const form = useForm<{ notes: string | null }>({
    notes: null,
});

function openForMatch(id: number, notes: string | null) {
    matchId.value = id;
    form.notes = notes ?? '';
    open.value = true;
}

function save() {
    if (matchId.value === null) return;

    form.submit(UpdateNotesController({ id: matchId.value }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

defineExpose({ openForMatch });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Match Notes</DialogTitle>
            </DialogHeader>
            <textarea
                v-model="form.notes"
                class="min-h-[120px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                placeholder="Add your notes about this match..."
            />
            <DialogFooter>
                <Button variant="outline" size="sm" @click="open = false">Cancel</Button>
                <Button size="sm" :disabled="form.processing" @click="save">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
