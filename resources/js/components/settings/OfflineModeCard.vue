<script setup lang="ts">
import RunSubmitMatchesController from '@/actions/App/Http/Controllers/Settings/RunSubmitMatchesController';
import UpdateOfflineModeController from '@/actions/App/Http/Controllers/Settings/UpdateOfflineModeController';
import DataDisclosure from '@/components/settings/DataDisclosure.vue';
import OfflineModeRejoinDialog from '@/components/settings/OfflineModeRejoinDialog.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useOfflineMode } from '@/composables/useOfflineMode';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import type { PendingMatch } from '@/types/settings';
import { computed, ref } from 'vue';

const props = defineProps<{
    lockedUntil: string | null;
    hasArchetypeCatalog: boolean;
    pendingMatches: PendingMatch[];
}>();

const { processing, send } = useSettingsRequest();
const offlineMode = useOfflineMode();

const rejoinDialogOpen = ref(false);

// Leaving offline mode starts a cooldown, so confirm before it happens rather
// than explaining afterwards. Turning it back ON is never gated behind a
// dialog: privacy should not need confirming.
function toggleOfflineMode(val: boolean) {
    if (!val) {
        rejoinDialogOpen.value = true;

        return;
    }

    send('offlineMode', 'patch', UpdateOfflineModeController.url(), { enabled: true });
}

function confirmRejoin() {
    rejoinDialogOpen.value = false;
    send('offlineMode', 'patch', UpdateOfflineModeController.url(), { enabled: false });
}

const locked = computed<boolean>(() => {
    if (props.lockedUntil === null) {
        return false;
    }

    return new Date(props.lockedUntil).getTime() > Date.now();
});

const rejoinBlocked = computed(() => !offlineMode.value && locked.value);

function submitPendingMatches() {
    send('submitMatches', 'post', RunSubmitMatchesController.url());
}

const pendingLabel = computed(() => {
    const count = props.pendingMatches.length;

    return `Submit ${count} match${count === 1 ? '' : 'es'}`;
});
</script>

<template>
    <SettingsSection title="Offline mode" description="Control what data is collected from your use of the app.">
        <div class="flex items-center justify-between gap-4">
            <div>
                <Label>Offline mode</Label>
                <p class="text-sm text-muted-foreground">
                    No data leaves this device and no community data comes in. Your matches stay private, so you won't get community card stats,
                    opponent scouting or archetype updates. Card data still refreshes so your decks keep working.
                </p>
            </div>
            <Switch
                :modelValue="offlineMode"
                @update:modelValue="toggleOfflineMode"
                :disabled="processing === 'offlineMode' || rejoinBlocked"
                :title="rejoinBlocked ? 'Offline mode is on cooldown until tomorrow after coming back online.' : undefined"
            />
        </div>
        <p v-if="rejoinBlocked" class="text-xs text-warning">
            Offline mode is on cooldown after coming back online. You can turn it on again tomorrow.
        </p>
        <p v-if="!hasArchetypeCatalog" class="text-xs text-warning">
            <template v-if="offlineMode">
                No archetype catalog has been downloaded yet, so deck archetype detection is unreliable until you turn offline mode off long enough to
                sync one.
            </template>
            <template v-else>
                No archetype catalog has been downloaded yet. Turning offline mode on now would leave deck archetype detection unreliable until one
                syncs.
            </template>
        </p>
        <div v-if="!offlineMode" class="flex items-center justify-between">
            <p class="text-sm text-muted-foreground">{{ pendingMatches.length }} matches pending.</p>
            <Button variant="outline" size="sm" :disabled="processing === 'submitMatches' || !pendingMatches.length" @click="submitPendingMatches">
                <Spinner v-if="processing === 'submitMatches'" />
                {{ processing === 'submitMatches' ? 'Submitting...' : pendingLabel }}
            </Button>
        </div>
        <Separator />
        <DataDisclosure />

        <OfflineModeRejoinDialog v-model:open="rejoinDialogOpen" :submitting="processing === 'offlineMode'" @confirm="confirmRejoin" />
    </SettingsSection>
</template>
