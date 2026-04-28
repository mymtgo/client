<script setup lang="ts">
import DownloadArchetypesController from '@/actions/App/Http/Controllers/Setup/DownloadArchetypesController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    archetypeCount: number;
    skipped: boolean;
}>();

const emit = defineEmits<{ continue: [] }>();

const processing = ref(false);

const error = computed(() => (usePage().props.flash as { setupArchetypeError?: string } | undefined)?.setupArchetypeError);

const start = () => {
    processing.value = true;
    router.post(
        DownloadArchetypesController.download.url(),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};

const skip = () => {
    router.post(DownloadArchetypesController.skip.url(), {}, {
        preserveScroll: true,
        onSuccess: () => emit('continue'),
    });
};
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <h2 class="text-xl font-semibold">Download archetypes</h2>
            <p class="text-sm text-muted-foreground">
                We'll download the latest archetype definitions so your matches can be classified automatically.
            </p>
        </div>

        <div v-if="processing" class="flex items-center gap-3 rounded-md border bg-muted/40 p-4 text-sm">
            <Spinner />
            <span>Downloading archetypes…</span>
        </div>

        <div v-else-if="error" class="rounded-md border border-destructive/40 bg-destructive/10 p-4 text-sm text-destructive">
            {{ error }}
        </div>

        <div v-else-if="props.archetypeCount > 0 || props.skipped" class="rounded-md border bg-muted/40 p-4 text-sm">
            <span v-if="props.archetypeCount > 0">{{ props.archetypeCount }} archetypes ready.</span>
            <span v-else>Skipped — you can download archetypes later from Settings.</span>
        </div>

        <div class="flex justify-between">
            <Button v-if="!processing" variant="ghost" @click="skip">Skip for now</Button>
            <div class="flex gap-2">
                <Button v-if="!processing && !error && props.archetypeCount === 0 && !props.skipped" @click="start">
                    Download archetypes
                </Button>
                <Button v-if="!processing && error" @click="start">Try again</Button>
                <Button v-if="!processing && (props.archetypeCount > 0 || props.skipped)" @click="emit('continue')">Continue</Button>
            </div>
        </div>
    </div>
</template>
