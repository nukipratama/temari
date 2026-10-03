import { DollarSign } from 'lucide-react';

import DevtoolsHeader from '@/components/narration/DevtoolsHeader';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'DevtoolsHeader',
    description:
        "The operator screens' title band, with the way back to the devtools index.",
    usage: `<DevtoolsHeader icon={DollarSign} title="Narration" />`,
    states: [
        { name: 'title only', render: () => <DevtoolsHeader title="Design" /> },
        {
            name: 'with icon and a subline',
            render: () => (
                <DevtoolsHeader icon={DollarSign} title="Narration">
                    <p className="text-meta">last 30 days</p>
                </DevtoolsHeader>
            ),
        },
    ],
} satisfies CatalogueEntry;
