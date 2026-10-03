import EffortLegend from '@/components/history/EffortLegend';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'EffortLegend',
    description:
        "The key to History's effort colours, and to the mood dots on the calendar.",
    usage: `<EffortLegend withMood />`,
    states: [
        { name: 'effort', render: () => <EffortLegend /> },
        { name: 'with mood', render: () => <EffortLegend withMood /> },
    ],
} satisfies CatalogueEntry;
