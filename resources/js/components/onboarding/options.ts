import {
    Flag,
    Layers,
    RotateCcw,
    Sprout,
    Target,
    Trophy,
    Undo2,
} from 'lucide-react';

import type { IconComponent } from '@/components/ui/Icon';
import type { ExperienceLevel, GoalType } from '@/types/generated';

export const EXPERIENCE_OPTIONS: ReadonlyArray<{
    value: ExperienceLevel;
    label: string;
    description: string;
    icon: IconComponent;
}> = [
    {
        value: 'new_to_running',
        label: 'new to running',
        description: 'first few months, learning the ropes.',
        icon: Sprout,
    },
    {
        value: 'returning',
        label: 'getting back into it',
        description: 'coming back after time off.',
        icon: RotateCcw,
    },
    {
        value: 'experienced',
        label: 'experienced',
        description: 'know your paces, chasing more.',
        icon: Trophy,
    },
];

export const GOAL_OPTIONS: ReadonlyArray<{
    value: GoalType;
    label: string;
    description: string;
    icon: IconComponent;
}> = [
    {
        value: 'consistent',
        label: 'stay consistent',
        description: 'show up steady, week after week.',
        icon: Target,
    },
    {
        value: 'race',
        label: 'chase a race time',
        description: 'training toward a real finish time.',
        icon: Flag,
    },
    {
        value: 'base',
        label: 'build a base',
        description: 'stack easy miles, no pressure yet.',
        icon: Layers,
    },
    {
        value: 'return',
        label: 'ease back in',
        description: 'rebuilding gently after a break.',
        icon: Undo2,
    },
];

/** The three answered preferences, joined the way the prototype's recap line
 *  joins them. Empty when every question was skipped. */
export function preferencesSummary(
    experienceLevel: ExperienceLevel | null,
    sessionsPerWeek: number | null,
    goalType: GoalType | null,
): string {
    const parts: string[] = [];
    const experience = EXPERIENCE_OPTIONS.find(
        (option) => option.value === experienceLevel,
    );
    if (experience) {
        parts.push(experience.label);
    }
    if (sessionsPerWeek !== null) {
        parts.push(`${sessionsPerWeek}x a week`);
    }
    const goal = GOAL_OPTIONS.find((option) => option.value === goalType);
    if (goal) {
        parts.push(goal.label);
    }

    return parts.join(' · ');
}
