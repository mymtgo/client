<script setup lang="ts">
import UpdateWatcherController from '@/actions/App/Http/Controllers/Settings/UpdateWatcherController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import { computed } from 'vue';

const props = defineProps<{
    active: boolean;
    pathsValid: boolean;
}>();

const { processing, send } = useSettingsRequest();

const running = computed(() => props.active && props.pathsValid);

function toggle() {
    send('watcher', 'patch', UpdateWatcherController.url(), { active: !props.active });
}
</script>

<template>
    <SettingsSection title="Watcher" description="Control the file system watcher that monitors log files and triggers ingestion.">
        <div class="flex items-center justify-between">
            <div>
                <Label>File Watcher</Label>
                <p class="text-sm text-muted-foreground">Monitors log files and triggers ingestion automatically.</p>
                <p v-if="!pathsValid" class="text-sm text-destructive">File paths must be valid to enable the watcher.</p>
            </div>
            <div class="flex items-center gap-3">
                <Badge :variant="running ? 'default' : 'secondary'">{{ running ? 'Running' : 'Stopped' }}</Badge>
                <Button variant="outline" size="sm" :disabled="!pathsValid || processing === 'watcher'" @click="toggle">
                    <Spinner v-if="processing === 'watcher'" />
                    {{ processing === 'watcher' ? 'Processing...' : running ? 'Stop' : 'Start' }}
                </Button>
            </div>
        </div>
    </SettingsSection>
</template>
