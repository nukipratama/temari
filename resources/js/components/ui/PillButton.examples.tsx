import type { ComponentProps } from 'react';

import { Check } from 'lucide-react';

import { Icon } from '@/components/ui/Icon';
import PillButton, { type PillTone } from '@/components/ui/PillButton';
import { axesOf, type CatalogueEntry } from '@/lib/catalogue';
import { pillButtonVariantMap } from '@/lib/variants';

type PillSize = ComponentProps<typeof PillButton>['size'];

export default {
    name: 'PillButton',
    description:
        "The app's button. One horizon CTA per view at most; secondary actions are outline or ghost, a destructive confirmation is danger.",
    usage: `<PillButton tone="horizon" onClick={save}>
    save goal
</PillButton>`,
    states: [
        { name: 'default', render: () => <PillButton>save</PillButton> },
        {
            name: 'with icon',
            render: () => (
                <PillButton tone="outline">
                    <Icon icon={Check} width={16} height={16} aria-hidden />
                    done
                </PillButton>
            ),
        },
        {
            name: 'disabled',
            render: () => (
                <PillButton tone="horizon" disabled>
                    save goal
                </PillButton>
            ),
        },
        {
            name: 'long copy',
            render: () => (
                <PillButton tone="outline">
                    reconnect Strava to bring the heart-rate zones back
                </PillButton>
            ),
        },
    ],
    matrix: {
        axes: axesOf(pillButtonVariantMap, ['tone', 'size']),
        render: ({ tone, size }) => (
            <PillButton tone={tone as PillTone} size={size as PillSize}>
                {tone}
            </PillButton>
        ),
    },
} satisfies CatalogueEntry;
