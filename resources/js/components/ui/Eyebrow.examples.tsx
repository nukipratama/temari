import type { ComponentProps } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';
import { axesOf, type CatalogueEntry } from '@/lib/catalogue';
import { eyebrowVariantMap } from '@/lib/variants';

type EyebrowProps = ComponentProps<typeof Eyebrow>;

export default {
    name: 'Eyebrow',
    description:
        'The mono uppercase label a section opens with. Section headings are eyebrows, never serif.',
    usage: `<Eyebrow token="small" tone="ink-3" as="h2">
    this week
</Eyebrow>`,
    states: [
        {
            name: 'default',
            render: () => (
                <Eyebrow token="small" tone="ink-3">
                    this week
                </Eyebrow>
            ),
        },
        {
            name: 'with rule',
            render: () => (
                <Eyebrow token="micro" tone="ink-3" rule>
                    splits
                </Eyebrow>
            ),
        },
        {
            name: 'on sky',
            render: () => (
                <div className="flex flex-col gap-2 rounded-md bg-sky pad-card">
                    <Eyebrow token="hero" tone="horizon">
                        today
                    </Eyebrow>
                    <Eyebrow token="small" tone="ink-on-sky">
                        last run
                    </Eyebrow>
                    <Eyebrow token="micro" tone="cream">
                        pace
                    </Eyebrow>
                </div>
            ),
        },
    ],
    matrix: {
        axes: axesOf(eyebrowVariantMap, ['tone', 'token']),
        omitted: {
            horizon: 'the lime fill is for sky panels, see the on sky state',
            'ink-on-sky': 'for sky panels only, see the on sky state',
            cream: 'for sky panels only, see the on sky state',
        },
        render: ({ tone, token }) => (
            <Eyebrow
                tone={tone as EyebrowProps['tone']}
                token={token as EyebrowProps['token']}
            >
                {token}
            </Eyebrow>
        ),
    },
} satisfies CatalogueEntry;
