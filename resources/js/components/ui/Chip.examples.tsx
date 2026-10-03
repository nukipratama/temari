import { Flame } from 'lucide-react';

import Chip, { type ChipTone } from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';
import { axesOf, type CatalogueEntry } from '@/lib/catalogue';
import { chipVariantMap } from '@/lib/variants';

export default {
    name: 'Chip',
    description:
        'A short tinted label: a verdict, a status, a count. Tone carries meaning, so pair it with a word, never colour alone.',
    usage: `<Chip tone="positive">holding</Chip>`,
    states: [
        { name: 'default', render: () => <Chip>4 runs</Chip> },
        {
            name: 'with icon',
            render: () => (
                <Chip tone="horizon" size="md">
                    <Icon icon={Flame} aria-hidden />
                    new best
                </Chip>
            ),
        },
        {
            name: 'on sky',
            render: () => (
                <div className="rounded-md bg-sky pad-card">
                    <Chip tone="onSky">this week</Chip>
                </div>
            ),
        },
    ],
    matrix: {
        axes: axesOf(chipVariantMap, ['tone', 'size']),
        omitted: {
            sky: 'no call site uses it, and its fixed-dark text disappears on the dark ground',
            onSky: 'for sky panels only, see the on sky state',
        },
        render: ({ tone, size }) => (
            <Chip tone={tone as ChipTone} size={size as 'sm' | 'md'}>
                {tone}
            </Chip>
        ),
    },
} satisfies CatalogueEntry;
