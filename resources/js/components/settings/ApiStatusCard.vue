<script setup lang="ts">
import CheckApiStatusController from '@/actions/App/Http/Controllers/Settings/CheckApiStatusController';
import ReauthenticateController from '@/actions/App/Http/Controllers/Settings/ReauthenticateController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { onMounted, ref } from 'vue';

type ApiStatus = { state: 'ok' } | { state: 'noauth'; message: string } | { state: 'unreachable'; error: string } | { state: 'offline' };

const status = ref<ApiStatus | null>(null);
const checking = ref(false);
const reauthenticating = ref(false);

async function check() {
    checking.value = true;
    try {
        const response = await fetch(CheckApiStatusController.url());

        if (!response.ok) {
            status.value = { state: 'unreachable', error: `HTTP ${response.status} from local proxy.` };
            return;
        }

        status.value = (await response.json()) as ApiStatus;
    } catch (e) {
        status.value = { state: 'unreachable', error: e instanceof Error ? e.message : String(e) };
    } finally {
        checking.value = false;
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function reauthenticate() {
    reauthenticating.value = true;
    try {
        const response = await fetch(ReauthenticateController.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
        });

        if (!response.ok) {
            status.value = { state: 'unreachable', error: `HTTP ${response.status} from local proxy.` };
            return;
        }

        status.value = (await response.json()) as ApiStatus;
    } catch (e) {
        status.value = { state: 'unreachable', error: e instanceof Error ? e.message : String(e) };
    } finally {
        reauthenticating.value = false;
    }
}

onMounted(() => {
    check();
});
</script>

<template>
    <SettingsSection title="API Status" description="Connection status to the mymtgo API.">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div
                    class="size-2 shrink-0 rounded-full"
                    :class="{
                        'animate-pulse bg-muted-foreground/40': status === null,
                        'bg-success': status?.state === 'ok',
                        'bg-warning': status?.state === 'offline',
                        'bg-destructive': status !== null && status.state !== 'ok' && status.state !== 'offline',
                    }"
                />
                <span
                    class="text-sm"
                    :class="{
                        'text-warning': status?.state === 'offline',
                        'text-destructive': status !== null && status.state !== 'ok' && status.state !== 'offline',
                        'text-muted-foreground': status === null || status.state === 'ok',
                    }"
                >
                    <template v-if="status === null">Checking…</template>
                    <template v-else-if="status.state === 'ok'">Connected</template>
                    <template v-else-if="status.state === 'noauth'">{{ status.message }}</template>
                    <template v-else-if="status.state === 'offline'">Offline mode is enabled</template>
                    <template v-else>Cannot reach API</template>
                </span>
            </div>
            <div class="flex items-center gap-2">
                <Button v-if="status && status.state === 'noauth'" variant="outline" size="sm" :disabled="reauthenticating" @click="reauthenticate">
                    <Spinner v-if="reauthenticating" />
                    {{ reauthenticating ? 'Reauthenticating…' : 'Reauthenticate' }}
                </Button>
                <Button variant="outline" size="sm" :disabled="checking || reauthenticating" @click="check">
                    <Spinner v-if="checking" />
                    {{ checking ? 'Checking…' : 'Recheck' }}
                </Button>
            </div>
        </div>

        <pre
            v-if="status?.state === 'unreachable'"
            class="max-h-40 overflow-auto rounded-md border border-destructive/40 bg-destructive/5 p-3 text-xs break-words whitespace-pre-wrap text-destructive"
            >{{ status.error }}</pre
        >
    </SettingsSection>
</template>
