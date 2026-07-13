<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppToast from '@/components/AppToast.vue';
import { useToast } from '@/composables/useToast';
import { Info } from 'lucide-vue-next';
import ResolvedNote from './ResolvedNote.vue';
import SinkSection from './SinkSection.vue';

const { add } = useToast();

const toastTypes: { type: string; title: string; message: string }[] = [
    { type: 'success', title: 'Deck synced', message: 'UW Control is up to date.' },
    { type: 'error', title: 'Sync failed', message: "Couldn't reach the MTGO log folder." },
    { type: 'match_win', title: 'Match won', message: 'Beat Mono Red Aggro 2-1.' },
    { type: 'match_loss', title: 'Match lost', message: 'Lost to UW Control 1-2.' },
    { type: 'match_voided', title: 'Match voided', message: 'Opponent disconnected — result discarded.' },
    { type: 'match_incomplete', title: 'Match incomplete', message: 'Client closed before the match finished.' },
];

const staticToast = {
    id: -1,
    type: 'success',
    title: 'Deck synced',
    message: 'UW Control is up to date.',
    duration: 60000,
};
</script>

<template>
    <SinkSection id="feedback" title="Feedback">
        <div class="flex flex-wrap items-center gap-3">
            <Button v-for="t in toastTypes" :key="t.type" variant="outline" @click="add(t)">
                {{ t.type }}
            </Button>
        </div>

        <ResolvedNote
            tag="unified"
            note="Toast and Alert share surface, radius, type and a 3px semantic left border. They differ only in position and lifetime."
        />
        <div class="flex flex-wrap items-start gap-6">
            <AppToast :toast="staticToast" />
            <Alert variant="info" class="max-w-sm">
                <Info class="size-4" />
                <AlertTitle>Heads up</AlertTitle>
                <AlertDescription>Alert covers the same ground as a toast for persistent, in-page messages.</AlertDescription>
            </Alert>
        </div>
    </SinkSection>
</template>
