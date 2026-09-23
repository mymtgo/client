import { ArrowDownToLine, Flag, Moon, Shield, Sparkle, Sparkles, Sunrise, Swords, Zap } from 'lucide-vue-next';
import type { Component } from 'vue';

export type ReplayStepKey =
    | 'Upkeep'
    | 'Draw'
    | 'PreCombatMain'
    | 'BeginCombat'
    | 'DeclareAttackers'
    | 'DeclareBlockers'
    | 'CombatDamage'
    | 'PostCombatMain'
    | 'EndOfTurn';

export type ReplayStep = { key: ReplayStepKey; label: string; icon: Component };

/** The rail's step icons, grouped by phase. */
export const REPLAY_PHASES: ReplayStep[][] = [
    [
        { key: 'Upkeep', label: 'Upkeep', icon: Sunrise },
        { key: 'Draw', label: 'Draw', icon: ArrowDownToLine },
    ],
    [{ key: 'PreCombatMain', label: 'Main 1', icon: Sparkle }],
    [
        { key: 'BeginCombat', label: 'Beginning of combat', icon: Flag },
        { key: 'DeclareAttackers', label: 'Declare attackers', icon: Swords },
        { key: 'DeclareBlockers', label: 'Declare blockers', icon: Shield },
        { key: 'CombatDamage', label: 'Combat damage', icon: Zap },
    ],
    [{ key: 'PostCombatMain', label: 'Main 2', icon: Sparkles }],
    [{ key: 'EndOfTurn', label: 'End of turn', icon: Moon }],
];

export const REPLAY_STEP_ORDER: ReplayStepKey[] = REPLAY_PHASES.flat().map((step) => step.key);

/** MTGO step and phase names that have no icon of their own fold into the nearest one. */
const ALIASES: Record<string, ReplayStepKey> = {
    Untap: 'Upkeep',
    Beginning: 'Upkeep',
    Combat: 'BeginCombat',
    FirstStrikeDamage: 'CombatDamage',
    EndOfCombat: 'CombatDamage',
    Ending: 'EndOfTurn',
    Cleanup: 'EndOfTurn',
};

export function normaliseStep(step: string | undefined, phase: string | undefined): ReplayStepKey | null {
    for (const name of [step, phase]) {
        if (!name) {
            continue;
        }

        if ((REPLAY_STEP_ORDER as string[]).includes(name)) {
            return name as ReplayStepKey;
        }

        if (ALIASES[name]) {
            return ALIASES[name];
        }
    }

    return null;
}

export function stepLabel(key: ReplayStepKey | null): string {
    return REPLAY_PHASES.flat().find((step) => step.key === key)?.label ?? '';
}
