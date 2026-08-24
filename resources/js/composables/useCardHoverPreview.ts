import { ref } from 'vue';

type PreviewableCard = { name: string; image: string | null };

/**
 * Hover card-image preview for overlay card rows, anchored to the right edge
 * of the window and clamped so the ~280px-tall image never leaves the
 * viewport. Shared by the draw odds panel and the sideboard guide.
 */
export function useCardHoverPreview<T extends PreviewableCard>() {
    const hoveredCard = ref<T | null>(null);
    const previewTop = ref(0);

    function onCardEnter(card: T, event: MouseEvent): void {
        if (!card.image) {
            return;
        }
        hoveredCard.value = card;
        const rowTop = (event.currentTarget as HTMLElement).getBoundingClientRect().top;
        const maxTop = window.innerHeight - 280;
        previewTop.value = Math.max(8, Math.min(rowTop, maxTop));
    }

    function onCardLeave(): void {
        hoveredCard.value = null;
    }

    return { hoveredCard, previewTop, onCardEnter, onCardLeave };
}
