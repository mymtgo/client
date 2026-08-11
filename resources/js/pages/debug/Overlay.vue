<script setup lang="ts">
import DebugNav from '@/components/debug/DebugNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { useToast } from '@/composables/useToast';
import { router } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const { add: toast } = useToast();

type SelectOption = { label: string; value: string; format: string };

const props = defineProps<{
    fakeMatch: {
        id: number;
        token: string;
        state: string;
        games: number;
        opponent: string | null;
        isSideboarding: boolean;
    } | null;
    deckOptions: SelectOption[];
    archetypeOptions: SelectOption[];
}>();

const deckId = ref('');
const archetypeId = ref('');
const opponentName = ref('');
const busy = ref(false);

// The opponent has to be playing the same format as the deck, so the
// archetype list narrows to the selected deck's format.
const deckFormat = computed(() => props.deckOptions.find((option) => option.value === deckId.value)?.format ?? null);

const archetypeOptions = computed(() =>
    deckFormat.value ? props.archetypeOptions.filter((option) => option.format === deckFormat.value) : props.archetypeOptions,
);

watch(deckFormat, () => {
    if (archetypeId.value && !archetypeOptions.value.some((option) => option.value === archetypeId.value)) {
        archetypeId.value = '';
    }
});

// router.delete takes (url, options) — there is no data argument. Routing a
// delete through the post signature silently drops the options, so onFinish
// never runs and `busy` sticks true, disabling every button.
function submit(method: 'post' | 'delete', url: string, data: Record<string, unknown> = {}) {
    busy.value = true;

    const options = {
        preserveScroll: true,
        onError: () => toast({ title: 'Simulator action failed', variant: 'destructive' }),
        onFinish: () => (busy.value = false),
    };

    if (method === 'delete') {
        router.delete(url, options);
    } else {
        router.post(url, data, options);
    }
}

function start() {
    submit('post', '/debug/overlay/fake-match', {
        deck_id: deckId.value,
        archetype_id: archetypeId.value,
        opponent_name: opponentName.value || null,
    });
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <DebugNav />

        <div class="flex max-w-xl flex-col gap-6 p-4">
            <div class="flex flex-col gap-3">
                <h2 class="text-sm font-semibold">Fake a live match</h2>
                <p class="text-xs text-muted-foreground">
                    Creates an in-progress match against a fake opponent using your real deck data, so the game
                    overlay can be reviewed without MTGO running. Open the overlay at
                    <code class="rounded bg-muted px-1">/game-overlay</code> in a browser tab, or via the real window
                    if the app shell is running.
                </p>

                <div class="flex flex-col gap-1.5">
                    <Label>Your deck</Label>
                    <NativeSelect v-model="deckId">
                        <NativeSelectOption value="" disabled>Pick a deck…</NativeSelectOption>
                        <NativeSelectOption v-for="option in props.deckOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </NativeSelectOption>
                    </NativeSelect>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label>Opponent archetype</Label>
                    <NativeSelect v-model="archetypeId">
                        <NativeSelectOption value="" disabled>Pick an archetype…</NativeSelectOption>
                        <NativeSelectOption v-for="option in archetypeOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </NativeSelectOption>
                    </NativeSelect>
                    <p class="text-xs text-muted-foreground">
                        Only archetypes with a downloaded decklist are listed — the opponent's revealed cards come
                        from it.
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label>Opponent name</Label>
                    <Input v-model="opponentName" placeholder="FakeOpponent" />
                    <p class="text-xs text-muted-foreground">
                        Use a real opponent's username to see your head-to-head record against them.
                    </p>
                </div>

                <Button :disabled="busy || !deckId || !archetypeId" @click="start">Start fake match</Button>
            </div>

            <div v-if="props.fakeMatch" class="flex flex-col gap-3 rounded-md border border-border p-3">
                <h2 class="text-sm font-semibold">Active fake match</h2>
                <dl class="grid grid-cols-2 gap-1 text-xs">
                    <dt class="text-muted-foreground">Token</dt>
                    <dd class="font-mono">{{ props.fakeMatch.token }}</dd>
                    <dt class="text-muted-foreground">Opponent</dt>
                    <dd>{{ props.fakeMatch.opponent ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Games</dt>
                    <dd>{{ props.fakeMatch.games }}</dd>
                    <dt class="text-muted-foreground">Sideboarding</dt>
                    <dd>{{ props.fakeMatch.isSideboarding ? 'yes' : 'no' }}</dd>
                </dl>

                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="busy || props.fakeMatch.isSideboarding"
                        @click="submit('post', '/debug/overlay/phase', { phase: 'sideboarding' })"
                    >
                        Enter sideboarding
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="busy || !props.fakeMatch.isSideboarding"
                        @click="submit('post', '/debug/overlay/phase', { phase: 'game2' })"
                    >
                        Start next game
                    </Button>
                    <Button variant="outline" size="sm" as-child>
                        <a href="/game-overlay" target="_blank">
                            <ExternalLink class="mr-1 size-3" />
                            Open overlay
                        </a>
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        :disabled="busy"
                        @click="submit('delete', '/debug/overlay/fake-match')"
                    >
                        Tear down
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
