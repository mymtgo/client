<script setup lang="ts">
import HomeController from '@/actions/App/Http/Controllers/IndexController';
import StartController from '@/actions/App/Http/Controllers/Upgrade/StartController';
import StatusController from '@/actions/App/Http/Controllers/Upgrade/StatusController';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Database } from 'lucide-vue-next';
import { computed, onUnmounted, ref } from 'vue';

interface PendingUpgrade {
    id: number;
    status: string;
    stage: string | null;
    progress: number;
    total: number;
    error: string | null;
}

const props = defineProps<{
    pendingUpgrade: PendingUpgrade | null;
}>();

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

type PageState = 'intro' | 'processing' | 'failed';

const resolveInitialState = (): PageState => {
    if (!props.pendingUpgrade) return 'intro';
    if (props.pendingUpgrade.status === 'failed') return 'failed';
    if (props.pendingUpgrade.status === 'pending' || props.pendingUpgrade.status === 'running') return 'processing';
    return 'intro';
};

const state = ref<PageState>(resolveInitialState());
const upgradeId = ref<number | null>(props.pendingUpgrade?.id ?? null);
const upgradeProgress = ref(props.pendingUpgrade?.progress ?? 0);
const upgradeTotal = ref(props.pendingUpgrade?.total ?? 0);
const upgradeStage = ref<string | null>(props.pendingUpgrade?.stage ?? null);
const upgradeError = ref<string | null>(props.pendingUpgrade?.error ?? null);
const starting = ref(false);

// ---------------------------------------------------------------------------
// Stage labels
// ---------------------------------------------------------------------------

const STAGE_LABELS: Record<string, string> = {
    participants: 'Migrating match participants…',
    archetypes: 'Updating archetypes…',
    cleanup: 'Finalizing…',
};

const stageLabel = computed(() => {
    if (!upgradeStage.value) return 'Preparing…';
    return STAGE_LABELS[upgradeStage.value] ?? upgradeStage.value;
});

const progressPercent = computed(() => {
    if (!upgradeTotal.value) return 0;
    return Math.min(100, Math.round((upgradeProgress.value / upgradeTotal.value) * 100));
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function jsonHeaders(): Record<string, string> {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': getCsrfToken(),
    };
}

// ---------------------------------------------------------------------------
// Polling
// ---------------------------------------------------------------------------

let pollTimer: ReturnType<typeof setInterval> | null = null;

function startPolling() {
    stopPolling();
    pollTimer = setInterval(pollStatus, 2000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

onUnmounted(stopPolling);

if (state.value === 'processing' && upgradeId.value) {
    startPolling();
}

async function pollStatus() {
    if (!upgradeId.value) return;

    try {
        const response = await fetch(StatusController.url(upgradeId.value), {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
        });

        if (!response.ok) return;

        const data = await response.json();
        upgradeProgress.value = data.progress ?? 0;
        upgradeTotal.value = data.total ?? 0;
        upgradeStage.value = data.stage ?? null;

        if (data.status === 'complete') {
            stopPolling();
            router.visit(HomeController.url());
        } else if (data.status === 'failed') {
            stopPolling();
            upgradeError.value = data.error ?? 'The upgrade failed.';
            state.value = 'failed';
        }
    } catch {
        // Silently retry on network error
    }
}

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

async function beginUpgrade() {
    if (starting.value) return;

    starting.value = true;
    upgradeError.value = null;

    try {
        const response = await fetch(StartController.url(), {
            method: 'POST',
            headers: jsonHeaders(),
        });

        if (!response.ok) {
            const text = await response.text();
            upgradeError.value = `Could not start upgrade (${response.status}): ${text || response.statusText}`;
            state.value = 'failed';
            return;
        }

        const data = await response.json();
        upgradeId.value = data.upgrade_id;
        upgradeProgress.value = 0;
        upgradeTotal.value = 0;
        upgradeStage.value = null;
        state.value = 'processing';
        startPolling();
    } catch (e) {
        upgradeError.value = `Could not start upgrade: ${e instanceof Error ? e.message : 'Unknown error'}`;
        state.value = 'failed';
    } finally {
        starting.value = false;
    }
}

function retry() {
    state.value = 'intro';
    upgradeId.value = null;
    upgradeError.value = null;
    upgradeProgress.value = 0;
    upgradeTotal.value = 0;
    upgradeStage.value = null;
}
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-background px-4">
        <div class="w-full max-w-md space-y-6">
            <!-- Header -->
            <div class="text-center">
                <div class="mb-4 flex justify-center">
                    <div class="flex size-14 items-center justify-center rounded-2xl bg-primary/10">
                        <Database class="size-7 text-primary" />
                    </div>
                </div>
                <h1 class="text-2xl font-semibold tracking-tight">Database upgrade required</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    A one-time migration is needed to update your match history to the new schema.
                </p>
            </div>

            <!-- STATE: Intro -->
            <template v-if="state === 'intro'">
                <Card>
                    <CardContent class="space-y-4 p-6">
                        <p class="text-sm text-muted-foreground">
                            This upgrade migrates match participant data, updates archetype records, and cleans up
                            any incomplete game entries. It runs once and cannot be skipped.
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Depending on your match history size, this may take a few seconds to a minute. The app
                            will return to the dashboard automatically when complete.
                        </p>
                        <Button class="w-full" size="lg" :disabled="starting" @click="beginUpgrade">
                            <Spinner v-if="starting" class="mr-2 size-4" />
                            {{ starting ? 'Starting…' : 'Begin upgrade' }}
                        </Button>
                    </CardContent>
                </Card>
            </template>

            <!-- STATE: Processing -->
            <template v-if="state === 'processing'">
                <Card>
                    <CardContent class="space-y-5 p-6 text-center">
                        <Spinner class="mx-auto size-8" />

                        <div>
                            <p class="text-sm font-medium">{{ stageLabel }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Do not close the app while the upgrade is running.
                            </p>
                        </div>

                        <div v-if="upgradeTotal > 0" class="space-y-1">
                            <div class="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    class="h-full rounded-full bg-primary transition-all duration-300"
                                    :style="{ width: progressPercent + '%' }"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ upgradeProgress }} / {{ upgradeTotal }} ({{ progressPercent }}%)
                            </p>
                        </div>

                        <div v-else class="h-2 overflow-hidden rounded-full bg-muted">
                            <div class="h-full animate-pulse rounded-full bg-primary/50" style="width: 100%" />
                        </div>
                    </CardContent>
                </Card>
            </template>

            <!-- STATE: Failed -->
            <template v-if="state === 'failed'">
                <Card class="border-destructive/50">
                    <CardContent class="space-y-4 p-6">
                        <div class="flex items-start gap-3">
                            <AlertTriangle class="mt-0.5 size-5 shrink-0 text-destructive" />
                            <div class="space-y-1">
                                <p class="text-sm font-medium">Upgrade failed</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ upgradeError ?? 'An unexpected error occurred during the upgrade.' }}
                                </p>
                            </div>
                        </div>
                        <Button class="w-full" variant="outline" @click="retry">
                            Try again
                        </Button>
                    </CardContent>
                </Card>
            </template>
        </div>
    </div>
</template>
