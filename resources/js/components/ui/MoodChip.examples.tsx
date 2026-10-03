import type { Mood } from '@/types/inertia';

import MoodChip from '@/components/ui/MoodChip';
import { type CatalogueEntry } from '@/lib/catalogue';

const MOODS: readonly Mood[] = [
    'blazing',
    'easy',
    'wobbly',
    'gassed',
    'overloaded',
    'chill',
];

export default {
    name: 'MoodChip',
    description:
        "A run's mood as a dot and a word, on the mood's own cell tint.",
    usage: `<MoodChip mood={run.mood} />`,
    states: [
        {
            name: 'every mood',
            render: () => (
                <div className="flex flex-wrap gap-2">
                    {MOODS.map((mood) => (
                        <MoodChip key={mood} mood={mood} />
                    ))}
                </div>
            ),
        },
        {
            name: 'md with a custom label',
            render: () => <MoodChip mood="easy" size="md" label="easy day" />,
        },
        {
            name: 'on sky',
            render: () => (
                <div className="rounded-md bg-sky pad-card">
                    <MoodChip mood="blazing" onSky />
                </div>
            ),
        },
    ],
} satisfies CatalogueEntry;
