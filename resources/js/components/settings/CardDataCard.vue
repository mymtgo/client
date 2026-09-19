<script setup lang="ts">
import RunPopulateCardsController from '@/actions/App/Http/Controllers/Settings/RunPopulateCardsController';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useSettingsRequest } from '@/composables/useSettingsRequest';
import { computed } from 'vue';

const props = defineProps<{
    total: number;
    incomplete: number;
}>();

const { processing, send } = useSettingsRequest();

const running = computed(() => processing.value === 'populateCards');

/**
 * A device that took its history from cloud sync starts with no cards at
 * all, so "none yet" is the state this control exists for.
 */
const summary = computed(() => {
    if (props.total === 0) {
        return 'No card details downloaded yet. Decks and matches will show without names or artwork until you download them.';
    }

    if (props.incomplete > 0) {
        return `${props.total} cards stored, ${props.incomplete} still missing details.`;
    }

    return `${props.total} cards stored, all with details.`;
});

function run() {
    send('populateCards', 'post', RunPopulateCardsController.url());
}
</script>

<template>
    <SettingsSection title="Card data" description="Download names, artwork and details for the cards your decks and matches reference.">
        <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <Label>Card details</Label>
                <p class="text-sm text-muted-foreground">{{ summary }}</p>
            </div>
            <Button variant="outline" class="shrink-0 cursor-pointer" :disabled="running" @click="run">
                {{ running ? 'Downloading...' : 'Download card data' }}
            </Button>
        </div>
    </SettingsSection>
</template>
