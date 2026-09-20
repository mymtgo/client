<script setup lang="ts">
import SyncController from '@/actions/App/Http/Controllers/Settings/SyncController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useOfflineMode } from '@/composables/useOfflineMode';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

type SyncStatus = {
    linked: boolean;
    lastSyncedAt: string | null;
    pending: number;
    notSynced: number;
    slots: Slots | null;
    rejections: number;
    lastError: string | null;
    pendingByType: TypeCounts;
    activity: string[];
    syncing: boolean;
};

type TypeCounts = { match: number; deck: number; league: number };

type Slots = { limit: number | null; used: number };

const status = ref<SyncStatus | null>(null);
const cloudDownload = ref<TypeCounts | null>(null);
const cloudSlots = ref<Slots | null>(null);
const cloudLoading = ref(false);
const activityBox = ref<HTMLElement | null>(null);
const loading = ref(false);
const linking = ref(false);
const awaitingApproval = ref(false);
const linkError = ref<string | null>(null);
const running = ref(false);
const unlinking = ref(false);

const emit = defineEmits<{ 'update:linked': [value: boolean] }>();

const offlineMode = useOfflineMode();

// The deck list below this card needs the same answer, and this card is the
// one that polls for it.
watch(
    () => status.value?.linked,
    (value) => emit('update:linked', value === true),
);

let pollTimer: ReturnType<typeof setInterval> | null = null;
let pollDeadline = 0;

function stopPolling() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function call(url: string): Promise<SyncStatus | null> {
    try {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });

        if (!response.ok) {
            return null;
        }

        return (await response.json()) as SyncStatus;
    } catch {
        return null;
    }
}

async function refreshAll() {
    await Promise.all([refresh(), fetchCloud()]);
}

async function refresh(options: { silent?: boolean } = {}) {
    // The 3s poll reuses this; only a manual refresh shows the button's
    // loading state, otherwise the poll strobes it.
    if (!options.silent) {
        loading.value = true;
    }
    try {
        const response = await fetch(SyncController.show.url(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            status.value = (await response.json()) as SyncStatus;
        }
    } catch {
        // Leave the last known status in place; the user can retry with the
        // refresh button.
    } finally {
        loading.value = false;
    }
}

// Polls status on the card's existing 3s interval / 240s deadline until
// `isDone` reports the wait is over, or the deadline passes regardless.
// `onSettled` runs on both exits, with whether the wait was actually
// satisfied, and is where the button that started the watch goes back to
// idle: startPolling calls stopPolling itself to clear a previous timer, so
// that reset cannot live there.
function startPolling(isDone: (status: SyncStatus) => boolean, onSettled: (done: boolean) => void) {
    stopPolling();
    pollDeadline = Date.now() + 240_000;
    pollTimer = setInterval(async () => {
        if (Date.now() > pollDeadline) {
            stopPolling();
            onSettled(false);
            return;
        }

        await refresh({ silent: true });

        if (status.value && isDone(status.value)) {
            stopPolling();
            onSettled(true);
            fetchCloud();
        }
    }, 3000);
}

async function link() {
    linkError.value = null;
    linking.value = true;

    const result = await call(SyncController.link.url());

    if (!result) {
        endLinkWait();
        linkError.value = 'Could not start sign-in. Try again.';
        return;
    }

    status.value = result;
    awaitingApproval.value = true;

    // LinkSyncDeviceJob opens the system browser; the consent screen comes
    // back as a mymtgo:// deep link handled outside this page, so nothing
    // here can observe it directly. The button holds its waiting state until
    // `linked` flips, the user gives up, or the deadline does.
    startPolling(
        (status) => status.linked,
        (done) => {
            endLinkWait();

            if (!done) {
                linkError.value = "Sign-in wasn't finished. Try again.";
            }
        },
    );
}

// Abandoning the wait only stops watching for the callback. A consent screen
// still open in the browser stays valid, and approving it later flips the
// status on the card's next refresh.
function cancelLink() {
    stopPolling();
    endLinkWait();
}

function endLinkWait() {
    linking.value = false;
    awaitingApproval.value = false;
}

async function unlink() {
    unlinking.value = true;
    const result = await call(SyncController.unlink.url());
    if (result) {
        status.value = result;
    }
    unlinking.value = false;
}

async function runSync() {
    running.value = true;

    const result = await call(SyncController.run.url());
    if (result) {
        status.value = result;
    }
    watchSyncRun();
}

// Polls while the run itself is active (`syncing` reads the activity feed, so
// it covers pull-only runs where local pending is already 0, and ends on
// completion or abort). The button holds its Syncing state for the poll's
// lifetime, so a click visibly did something.
function watchSyncRun() {
    running.value = true;

    startPolling(
        (status) => !status.syncing,
        () => {
            running.value = false;
        },
    );
}

// The server's live ledger wins over the copy the last sync run wrote down,
// so a device that has linked but never synced still shows real numbers.
const deckSlots = computed<Slots | null>(() => cloudSlots.value ?? status.value?.slots ?? null);

// Decks always sync, on every plan (spec 2026-09-10, rule 1). Only matches
// and leagues wait on a deck holding a slot, so only they belong in the
// figure the slot limit applies to.
const uploadCounts = computed<TypeCounts | null>(() =>
    status.value === null ? null : { match: status.value.pendingByType.match, deck: 0, league: status.value.pendingByType.league },
);

function formatCounts(counts: TypeCounts | null | undefined, empty: string): string {
    if (!counts) {
        return empty;
    }
    const plurals = { match: 'matches', deck: 'decks', league: 'leagues' } as const;
    const parts = (['match', 'deck', 'league'] as const)
        .filter((type) => counts[type] > 0)
        .map((type) => `${counts[type]} ${counts[type] === 1 ? type : plurals[type]}`);
    return parts.length > 0 ? parts.join(', ') : empty;
}

// Asks the server what it holds; deliberately not part of the 3s poll so a
// background card never hammers the API. Called on mount, manual refresh
// and when a watched sync run finishes.
async function fetchCloud() {
    cloudLoading.value = true;
    try {
        const response = await fetch(SyncController.cloud.url(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (response.ok) {
            const payload = (await response.json()) as { download: TypeCounts | null; slots: Slots | null };
            cloudDownload.value = payload.download;
            cloudSlots.value = payload.slots;
        }
    } catch {
        // Leave the previous numbers; offline or unlinked simply shows the
        // placeholder.
    } finally {
        cloudLoading.value = false;
    }
}

function formatLastSynced(value: string | null): string {
    if (!value) {
        return 'Never synced yet';
    }

    return new Date(value).toLocaleString();
}

// Keep the activity console pinned to its newest line as the poll appends.
watch(
    () => status.value?.activity.length ?? 0,
    async () => {
        await nextTick();
        activityBox.value?.scrollTo({ top: activityBox.value.scrollHeight });
    },
);

onMounted(async () => {
    await refresh();
    fetchCloud();

    // A run started elsewhere (the half-hourly schedule, a match-completion
    // trigger, or a Sync now from before a navigation) should be watched
    // the same way as one started here.
    if (status.value?.syncing) {
        watchSyncRun();
    }
});

onBeforeUnmount(() => {
    stopPolling();
});
</script>

<template>
    <SettingsSection title="MyMTGO account" description="Sign in to MyMTGO to keep your matches backed up and in sync across devices.">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div
                    class="size-2 shrink-0 rounded-full"
                    :class="{
                        'animate-pulse bg-muted-foreground/40': status === null,
                        'bg-success': status?.linked,
                        'bg-muted-foreground/40': status !== null && !status.linked,
                    }"
                />
                <span class="text-sm text-muted-foreground">
                    <template v-if="status === null">Checking...</template>
                    <template v-else-if="status.linked">Signed in</template>
                    <template v-else>Not signed in</template>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    v-if="!status?.linked"
                    variant="outline"
                    size="sm"
                    :disabled="linking || offlineMode"
                    :title="offlineMode ? 'Turn off offline mode to sign in.' : undefined"
                    @click="link"
                >
                    <Spinner v-if="linking" />
                    <template v-if="awaitingApproval">Waiting for approval...</template>
                    <template v-else-if="linking">Opening browser...</template>
                    <template v-else>Sign in</template>
                </Button>
                <Button v-if="awaitingApproval" variant="ghost" size="sm" @click="cancelLink">Cancel</Button>
                <Button variant="outline" size="sm" :disabled="loading || cloudLoading" @click="refreshAll">
                    <Spinner v-if="loading" />
                    {{ loading ? 'Checking...' : 'Refresh' }}
                </Button>
            </div>
        </div>

        <p v-if="status !== null && !status.linked" class="text-xs text-muted-foreground">
            Signing in opens MyMTGO in your browser. Sign in or create a free account, then approve this device.
        </p>

        <p v-if="linkError" class="text-xs text-destructive">{{ linkError }}</p>

        <p v-if="offlineMode" class="text-xs text-warning">Offline mode is on. Turn it off in Data &amp; Privacy to sync.</p>

        <template v-if="status?.linked">
            <Separator />

            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">Last synced</span>
                    <span class="text-sm">{{ formatLastSynced(status.lastSyncedAt) }}</span>
                </div>
                <div class="flex flex-col gap-1">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-muted-foreground">Deck list</span>
                        <span class="text-sm">{{ status.pendingByType.deck > 0 ? `${status.pendingByType.deck} to send` : 'Up to date' }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">Your deck list always syncs, on every plan.</p>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">Ready to upload</span>
                    <span class="text-sm">{{ formatCounts(uploadCounts, 'Nothing') }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">Waiting in the cloud</span>
                    <span class="text-sm">{{ cloudLoading && !cloudDownload ? 'Checking...' : formatCounts(cloudDownload, 'Nothing new') }}</span>
                </div>
                <div v-if="deckSlots && deckSlots.limit !== null" class="flex flex-col gap-1">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-muted-foreground">Deck slots</span>
                        <span class="text-sm">{{ deckSlots.used }} of {{ deckSlots.limit }} used</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Your slot is for constructed decks. Choose which one syncs below. Supporter lifts the limit and adds draft and sealed decks.
                    </p>
                </div>
                <p v-if="status.notSynced > 0" class="text-xs text-warning">
                    {{ status.notSynced }} deleted deck{{ status.notSynced === 1 ? '' : 's' }} or league{{ status.notSynced === 1 ? '' : 's' }}
                    {{ status.notSynced === 1 ? 'is' : 'are' }} not synced.
                </p>
                <p v-if="status.rejections > 0" class="text-xs text-destructive">
                    {{ status.rejections }} row{{ status.rejections === 1 ? '' : 's' }} were rejected by the server and are not syncing.
                </p>
                <p v-if="status.lastError" class="text-xs text-destructive">The last sync run failed: {{ status.lastError }}</p>

                <div
                    v-if="status.activity.length > 0"
                    ref="activityBox"
                    class="max-h-40 overflow-auto rounded-lg border border-border bg-background p-3"
                >
                    <pre class="text-xs leading-relaxed text-muted-foreground">{{ status.activity.join('\n') }}</pre>
                </div>
            </div>

            <Separator />

            <div class="flex items-center justify-between">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="running || offlineMode"
                    :title="offlineMode ? 'Turn off offline mode to sync.' : undefined"
                    @click="runSync"
                >
                    <Spinner v-if="running" />
                    {{ running ? 'Syncing...' : 'Sync now' }}
                </Button>
                <Button variant="ghost" size="sm" :disabled="unlinking" @click="unlink">
                    <Spinner v-if="unlinking" />
                    {{ unlinking ? 'Signing out...' : 'Sign out' }}
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">You can also sign this device out from your account's Devices page on the website.</p>
        </template>
    </SettingsSection>
</template>
