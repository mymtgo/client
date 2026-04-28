<script setup lang="ts">
import CompleteController from '@/actions/App/Http/Controllers/Setup/CompleteController';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';

const finish = (next: 'app' | 'import') => {
    router.post(CompleteController.url(), { next });
};
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <h2 class="text-xl font-semibold">All set</h2>
            <p class="text-sm text-muted-foreground">
                Want to import your past matches now? You can always do this later from the Import page.
            </p>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" @click="finish('app')">Skip — go to app</Button>
            <Button @click="finish('import')">Import past matches</Button>
        </div>
    </div>
</template>
