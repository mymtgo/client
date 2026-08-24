import { computed, type Ref } from 'vue';

/**
 * Raw MTGO format codes (`CModern`) mapped to the lowercased `archetypes.format`
 * values `DownloadArchetypes` stores. Keys are uppercased and matched
 * case-insensitively, so both a raw code and an already-humanised `Modern`
 * resolve to the same value.
 */
const formatMap: Record<string, string> = {
    CMODERN: 'modern',
    CPAUPER: 'pauper',
    CLEGACY: 'legacy',
    CVINTAGE: 'vintage',
    CPREMODERN: 'premodern',
};

export function useArchetypeSplit(
    archetypes: Ref<App.Data.Front.ArchetypeData[]>,
    format: Ref<string | null>,
    search: Ref<string>,
) {
    const matchesFormat = (a: App.Data.Front.ArchetypeData): boolean => {
        if (a.isFallback) {
            return true;
        }
        if (!format.value) {
            return true;
        }
        const mapped = formatMap[format.value.toUpperCase()] ?? format.value.toLowerCase();
        return a.format === mapped;
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
