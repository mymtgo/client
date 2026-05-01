<script setup lang="ts">
import UpdateNotesController from '@/actions/App/Http/Controllers/Leagues/UpdateNotesController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useToast } from '@/composables/useToast';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const open = ref(false);
const leagueId = ref<number | null>(null);
const { add: toast } = useToast();

const form = useForm<{ notes: string | null }>({
    notes: null,
});

function openForLeague(id: number, notes: string | null) {
    leagueId.value = id;
    form.notes = notes ?? '';
    open.value = true;
}

function save() {
    if (leagueId.value === null) return;

    form.submit(UpdateNotesController({ league: leagueId.value }), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            toast({
                type: 'success',
                title: 'Notes saved',
                message: 'League notes updated.',
            });
        },
    });
}

defineExpose({ openForLeague });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>League Notes</DialogTitle>
            </DialogHeader>
            <textarea
                v-model="form.notes"
                class="min-h-[120px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                placeholder="Add your notes about this league run..."
            />
            <DialogFooter>
                <Button variant="outline" size="sm" @click="open = false">Cancel</Button>
                <Button size="sm" :disabled="form.processing" @click="save">Save</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
