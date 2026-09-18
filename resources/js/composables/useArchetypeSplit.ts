import { computed, type Ref } from 'vue';

/**
 * The `archetypes.format` key for a deck's format, raw code or display label.
 * Mirrors `MtgoFormat::key()`: MTGO prefixes its format codes with
 * a `C` (`CModern`, `CSTANDARD`), so the prefix is stripped and the rest
 * lowercased. A generic rule rather than a lookup, so a format the lookup
 * forgot cannot silently filter every archetype out.
 */
export function archetypeFormatKey(format: string): string {
    const raw = /^C[A-Z]/.test(format) ? format.slice(1) : format;
    return raw.toLowerCase();
}

export function useArchetypeSplit(archetypes: Ref<App.Data.Front.ArchetypeData[]>, format: Ref<string | null>, search: Ref<string>) {
    const matchesFormat = (a: App.Data.Front.ArchetypeData): boolean => {
        if (a.isFallback) {
            return true;
        }
        if (!format.value) {
            return true;
        }
        return a.format === archetypeFormatKey(format.value);
    };

    const matchesSearch = (a: App.Data.Front.ArchetypeData): boolean => {
        const q = search.value.toLowerCase().trim();
        if (!q) {
            return true;
        }
        return a.name.toLowerCase().includes(q);
    };

    const formatFiltered = computed(() => archetypes.value.filter(matchesFormat));

    const fallbacks = computed(() => formatFiltered.value.filter((a) => a.isFallback));

    const regular = computed(() => formatFiltered.value.filter((a) => !a.isFallback && matchesSearch(a)));

    return { fallbacks, regular };
}
