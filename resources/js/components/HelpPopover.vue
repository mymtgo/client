<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LifeBuoy, ExternalLink, Download } from 'lucide-vue-next';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Button } from '@/components/ui/button';
import DownloadReportBundleController from '@/actions/App/Http/Controllers/Support/DownloadReportBundleController';
import SettingsIndexController from '@/actions/App/Http/Controllers/Settings/IndexController';

const page = usePage();

const discordInviteUrl = computed(
    () => (page.props.support as { discordInviteUrl: string | null } | undefined)?.discordInviteUrl ?? null,
);

const downloading = ref(false);
const error = ref<{ message: string; showSettingsLink: boolean } | null>(null);

async function downloadBundle(): Promise<void> {
    downloading.value = true;
    error.value = null;

    try {
        const response = await fetch(DownloadReportBundleController.url());

        if (response.status === 404) {
            error.value = {
                message: "Couldn't find log files — check your log path in settings.",
                showSettingsLink: true,
            };
            return;
        }

        if (!response.ok) {
            error.value = {
                message: 'Something went wrong building the report bundle.',
                showSettingsLink: false,
            };
            return;
        }

        const blob = await response.blob();
        const filename = filenameFromContentDisposition(response.headers.get('content-disposition'))
            ?? 'mtgo-report.zip';

        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
        console.error('Failed to download report bundle', e);
        error.value = { message: 'Something went wrong building the report bundle.', showSettingsLink: false };
    } finally {
        downloading.value = false;
    }
}

function filenameFromContentDisposition(header: string | null): string | null {
    if (!header) return null;
    const match = header.match(/filename="?([^";]+)"?/);
    return match ? match[1] : null;
}
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
            >
                <LifeBuoy class="size-3.5" />
                <span>Get help</span>
            </button>
        </PopoverTrigger>

        <PopoverContent
            side="top"
            align="end"
            class="w-80 space-y-3"
        >
            <div class="space-y-1">
                <p class="text-sm font-semibold">Need help?</p>
                <p class="text-xs text-muted-foreground">
                    Hop into our Discord server to report bugs or ask questions.
                    Including your report data helps a lot when reporting an issue.
                </p>
            </div>

            <div class="flex flex-col gap-2">
                <Button
                    v-if="discordInviteUrl"
                    as="a"
                    :href="discordInviteUrl"
                    target="_blank"
                    rel="noreferrer"
                    class="w-full"
                >
                    <ExternalLink class="size-4" />
                    Open Discord
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    class="w-full"
                    :disabled="downloading"
                    @click="downloadBundle"
                >
                    <Download class="size-4" />
                    {{ downloading ? 'Preparing…' : 'Download report data' }}
                </Button>

                <p
                    v-if="error"
                    class="text-xs text-destructive"
                >
                    {{ error.message }}
                    <Link
                        v-if="error.showSettingsLink"
                        :href="SettingsIndexController.url()"
                        class="underline"
                    >
                        Open settings
                    </Link>
                </p>
                <p
                    v-else
                    class="text-xs text-muted-foreground"
                >
                    This zips your MTGO log, Laravel log, and recent pipeline logs to help with debugging — you can optionally upload it in Discord.
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
