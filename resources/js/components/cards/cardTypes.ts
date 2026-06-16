import { Castle, Flame, Gem, HandFist, MountainSnow, Origami, PanelRightOpen, ScrollText, Zap } from 'lucide-vue-next';
import type { Component } from 'vue';

export type CardTypeKey = 'Creature' | 'Instant' | 'Sorcery' | 'Enchantment' | 'Artifact' | 'Land' | 'Planeswalker' | 'Battle' | 'Sideboard';

export type CardTypeOption = { key: CardTypeKey; label: string; icon: Component };

export const CARD_TYPE_OPTIONS: Record<CardTypeKey, CardTypeOption> = {
    Creature: { key: 'Creature', label: 'Creatures', icon: Origami },
    Instant: { key: 'Instant', label: 'Instants', icon: Zap },
    Sorcery: { key: 'Sorcery', label: 'Sorceries', icon: Flame },
    Enchantment: { key: 'Enchantment', label: 'Enchantments', icon: ScrollText },
    Artifact: { key: 'Artifact', label: 'Artifacts', icon: Gem },
    Land: { key: 'Land', label: 'Lands', icon: MountainSnow },
    Planeswalker: { key: 'Planeswalker', label: 'Planeswalkers', icon: HandFist },
    Battle: { key: 'Battle', label: 'Battles', icon: Castle },
    Sideboard: { key: 'Sideboard', label: 'Sideboard', icon: PanelRightOpen },
};

/** Canonical card-type categories (no Sideboard pseudo-type), in display order. */
export const CARD_TYPE_KEYS: CardTypeKey[] = ['Creature', 'Instant', 'Sorcery', 'Enchantment', 'Artifact', 'Land', 'Planeswalker', 'Battle'];
