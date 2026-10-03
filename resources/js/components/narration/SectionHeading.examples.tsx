import { Activity, Coins, Gauge, Users } from 'lucide-react';

import SectionHeading from '@/components/narration/SectionHeading';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'SectionHeading',
    description:
        "An operator screen's section title: an icon in a tinted circle, a short accent rule, and an optional caption.",
    usage: `<SectionHeading icon={Coins} title="spend by kind" subtitle="the last 7 days" tone="brand" />`,
    states: [
        {
            name: 'every tone',
            render: () => (
                <div className="flex flex-col gap-5">
                    <SectionHeading
                        icon={Coins}
                        title="spend by kind"
                        subtitle="the last 7 days"
                    />
                    <SectionHeading
                        icon={Users}
                        title="athletes"
                        tone="accent"
                    />
                    <SectionHeading icon={Gauge} title="ceilings" tone="pop" />
                    <SectionHeading
                        icon={Activity}
                        title="deployments"
                        tone="neutral"
                    />
                </div>
            ),
        },
        {
            name: 'no icon',
            render: () => <SectionHeading title="today" />,
        },
    ],
} satisfies CatalogueEntry;
