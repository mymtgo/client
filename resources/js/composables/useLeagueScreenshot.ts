import { toPng } from 'html-to-image';
import { ref } from 'vue';
import { useToast } from './useToast';

export function useLeagueScreenshot() {
    const capturing = ref(false);
    const { add } = useToast();

    async function capture(element: HTMLElement) {
        if (capturing.value) return;
        capturing.value = true;

        try {
            const dataUrl = await toPng(element, {
                pixelRatio: 2,
                includeQueryParams: true,
                fontEmbedCSS: '',
            });

            const response = await fetch(dataUrl);
            const blob = await response.blob();

            try {
                await navigator.clipboard.write([
                    new ClipboardItem({ 'image/png': blob }),
                ]);
                add({ type: 'success', title: 'Copied!', message: 'League screenshot copied to clipboard' });
            } catch {
                add({ type: 'error', title: 'Copy failed', message: 'Could not write to clipboard' });
            }
        } catch (error) {
            console.error('[LeagueScreenshot] Capture failed:', error);
            add({ type: 'error', title: 'Screenshot failed', message: 'Could not capture the element' });
        }

        capturing.value = false;
    }

    return { capture, capturing };
}
