<script setup lang="ts">
import DropController from '@/actions/App/Http/Controllers/Leagues/DropController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { router } from '@inertiajs/vue3';
import { TriangleAlert } from 'lucide-vue-next';
import { ref } from 'vue';

const open = ref(false);
const leagueId = ref<number | null>(null);
const processing = ref(false);

function openForLeague(id: number) {
    leagueId.value = id;
    open.value = true;
}

function confirm() {
    if (leagueId.value === null) return;

    processing.value = true;
    router.patch(
        DropController({ league: leagueId.value }).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
                open.value = false;
            },
        },
    );
}

defineExpose({ openForLeague });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Drop league?</DialogTitle>
            </DialogHeader>
            <div class="space-y-3 text-sm">
                <p>This marks the run as dropped in your tracker.</p>
                <div
                    class="flex gap-2 rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-amber-400"
                >
                    <TriangleAlert class="size-4 shrink-0" />
                    <p class="text-xs">
                        This does <strong>not</strong> drop you from the league on MTGO. You must
                        do that in the MTGO client itself.
                    </p>
                </div>
            </div>
            <DialogFooter>
                <Button variant="outline" size="sm" @click="open = false">Cancel</Button>
                <Button
                    variant="destructive"
                    size="sm"
                    :disabled="processing"
                    @click="confirm"
                >
                    Drop league
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
