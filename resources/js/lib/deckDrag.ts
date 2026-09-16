/**
 * Deck ids travel between a deck card and a sidebar archetype row through
 * the native DataTransfer under a private MIME type. Drop targets can only
 * see `types` during dragover (payload data is protected until drop), so the
 * type check is what tells a deck drag apart from a stray text or file drag.
 */
export const DECK_DRAG_TYPE = 'application/x-mymtgo-deck-ids';

export function writeDeckDrag(dataTransfer: DataTransfer, ids: number[]): void {
    dataTransfer.setData(DECK_DRAG_TYPE, JSON.stringify(ids));
    dataTransfer.effectAllowed = 'move';
}

export function hasDeckDrag(dataTransfer: DataTransfer | null): boolean {
    return !!dataTransfer && Array.from(dataTransfer.types).includes(DECK_DRAG_TYPE);
}

export function readDeckDrag(dataTransfer: DataTransfer | null): number[] {
    if (!dataTransfer) return [];
    try {
        const parsed = JSON.parse(dataTransfer.getData(DECK_DRAG_TYPE));
        return Array.isArray(parsed) ? parsed.filter((id): id is number => typeof id === 'number') : [];
    } catch {
        return [];
    }
}

/**
 * Replace the browser's default drag ghost (a translucent copy of the whole
 * card) with a small pill naming what is being dragged. The pill has to be in
 * the DOM when setDragImage is called, so it is appended off-screen and
 * removed once the browser has captured it.
 */
export function setDeckDragImage(dataTransfer: DataTransfer, label: string): void {
    const pill = document.createElement('div');
    pill.textContent = label;
    pill.style.cssText = [
        'position:fixed',
        'top:-1000px',
        'left:-1000px',
        'max-width:240px',
        'padding:4px 10px',
        'border-radius:6px',
        'border:1px solid rgba(255,255,255,0.15)',
        'background:#171717',
        'color:#fff',
        'font-size:12px',
        'font-weight:500',
        'line-height:1.4',
        'white-space:nowrap',
        'overflow:hidden',
        'text-overflow:ellipsis',
        'box-shadow:0 4px 12px rgba(0,0,0,0.5)',
        'pointer-events:none',
    ].join(';');

    document.body.appendChild(pill);
    dataTransfer.setDragImage(pill, 12, 14);
    setTimeout(() => pill.remove(), 0);
}

export function deckDragLabel(count: number, singleName: string): string {
    return count > 1 ? `${count} decks` : singleName;
}
