<script setup lang="ts">
import OpenWebsiteLoginController from '@/actions/App/Http/Controllers/Auth/OpenWebsiteLoginController';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { router } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';

const opening = ref(false);

/** Browser round-trip started — the deep-link callback swaps this window out. */
const waiting = ref(false);

/** Set by the AuthCallbackFailed native event; cleared on retry. */
const error = ref<'cancelled' | 'failed' | null>(null);

onMounted(() => {
    window.Native?.on('App\\Events\\Auth\\AuthCallbackFailed', (payload) => {
        error.value = (payload as { reason?: string }).reason === 'cancelled' ? 'cancelled' : 'failed';
        waiting.value = false;
    });
});

function openWebsiteLogin() {
    opening.value = true;
    error.value = null;

    router.post(
        OpenWebsiteLoginController.url(),
        {},
        {
            onSuccess: () => (waiting.value = true),
            onFinish: () => (opening.value = false),
        },
    );
}
</script>

<template>
    <div class="texture-bg relative flex h-screen flex-col overflow-hidden">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-56 bg-[radial-gradient(ellipse_at_top,var(--primary-soft),transparent_70%)]" />

        <main class="relative flex flex-1 flex-col items-center justify-center gap-7 px-10 text-center">
            <div class="flex flex-col items-center gap-3">
                <span class="text-2xl font-semibold tracking-tight">mymtgo</span>
                <span class="rounded-full border border-line-2 bg-muted px-3 py-1 font-mono text-[10.5px] tracking-[0.08em] text-dim uppercase">
                    Signed out
                </span>
            </div>

            <p class="max-w-[32ch] text-[13.5px] leading-relaxed text-muted-foreground">
                Sign in on the mymtgo website to link this desktop app to your account.
            </p>

            <div class="flex flex-col items-center gap-4">
                <Button size="lg" :disabled="opening" @click="openWebsiteLogin">
                    <ExternalLink />
                    {{ waiting ? 'Reopen sign-in page' : 'Sign in on mymtgo.com' }}
                </Button>

                <div v-if="waiting" class="flex items-center gap-2 text-[13px] text-muted-foreground">
                    <Spinner class="size-[15px]" />
                    Waiting for you to finish in the browser…
                </div>

                <p v-if="error" role="alert" class="max-w-[36ch] text-[13px] leading-relaxed text-destructive">
                    {{ error === 'cancelled' ? 'Sign-in was cancelled.' : "Sign-in didn't complete — try again." }}
                </p>
            </div>
        </main>

        <footer class="relative px-10 pb-6 text-center text-xs text-dim">Your browser handles the sign-in — this app never sees your password.</footer>
    </div>
</template>
