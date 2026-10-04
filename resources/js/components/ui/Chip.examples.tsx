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
    ],
    matrix: {
        axes: axesOf(chipVariantMap, ['tone', 'size']),
        render: ({ tone, size }) => (
            <Chip tone={tone as ChipTone} size={size as 'sm' | 'md'}>
                {tone}
            </Chip>
        ),
    },
} satisfies CatalogueEntry;
