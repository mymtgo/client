<script setup lang="ts">
import UpdatePathsController from '@/actions/App/Http/Controllers/Setup/UpdatePathsController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLogPathSync, type PathStatus } from '@/composables/useLogPathSync';

const props = defineProps<{
    logPath: string;
    dataPath: string;
    logPathStatus: PathStatus;
    dataPathStatus: PathStatus;
}>();

const emit = defineEmits<{ continue: [] }>();

const logSync = useLogPathSync({ initial: props.logPath, saveUrl: UpdatePathsController.logPath.url() });
const dataSync = useLogPathSync({ initial: props.dataPath, saveUrl: UpdatePathsController.dataPath.url() });

const canContinue = () => props.logPathStatus.valid && props.dataPathStatus.valid;
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <h2 class="text-xl font-semibold">Locate your MTGO files</h2>
            <p class="text-sm text-muted-foreground">
                We need two folders from your MTGO install — one with log files and one with game data.
            </p>
        </div>

        <div class="space-y-2">
            <Label>MTGO log folder</Label>
            <div class="flex gap-2">
                <Input v-model="logSync.input.value" :disabled="logSync.processing.value" />
                <Button variant="outline" :disabled="logSync.processing.value" @click="logSync.browse">Browse</Button>
                <Button variant="outline" :disabled="logSync.processing.value || logSync.input.value === logPath" @click="logSync.save">
                    <Spinner v-if="logSync.processing.value" />
                    {{ logSync.processing.value ? 'Saving…' : 'Save' }}
                </Button>
            </div>
            <div v-if="logPath" class="flex items-center gap-2">
                <div class="size-2 shrink-0 rounded-full" :class="logPathStatus.valid ? 'bg-primary' : 'bg-destructive'" />
                <span class="text-sm" :class="logPathStatus.valid ? 'text-muted-foreground' : 'text-destructive'">
                    {{ logPathStatus.message }}
                </span>
            </div>
        </div>

        <div class="space-y-2">
            <Label>MTGO game data folder</Label>
            <div class="flex gap-2">
                <Input v-model="dataSync.input.value" :disabled="dataSync.processing.value" />
                <Button variant="outline" :disabled="dataSync.processing.value" @click="dataSync.browse">Browse</Button>
                <Button variant="outline" :disabled="dataSync.processing.value || dataSync.input.value === dataPath" @click="dataSync.save">
                    <Spinner v-if="dataSync.processing.value" />
                    {{ dataSync.processing.value ? 'Saving…' : 'Save' }}
                </Button>
            </div>
            <div v-if="dataPath" class="flex items-center gap-2">
                <div class="size-2 shrink-0 rounded-full" :class="dataPathStatus.valid ? 'bg-primary' : 'bg-destructive'" />
                <span class="text-sm" :class="dataPathStatus.valid ? 'text-muted-foreground' : 'text-destructive'">
                    {{ dataPathStatus.message }}
                </span>
            </div>
        </div>

        <div class="flex justify-end">
            <Button :disabled="!canContinue()" @click="emit('continue')">Continue</Button>
        </div>
    </div>
</template>
