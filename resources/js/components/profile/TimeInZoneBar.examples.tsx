import TimeInZoneBar from '@/components/profile/TimeInZoneBar';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'TimeInZoneBar',
    description:
        'Where training time went across Z1-Z5: one segmented bar and a dot legend. Renders nothing without heart-rate time.',
    usage: `<TimeInZoneBar zones={profile.timeInZone} />`,
    states: [
        {
            name: 'twelve weeks',
            render: () => (
                <TimeInZoneBar
                    zones={{ Z1: 18, Z2: 52, Z3: 17, Z4: 9, Z5: 4 }}
                />
            ),
        },
        {
            name: 'one run, anchored',
            render: () => (
                <TimeInZoneBar
                    zones={{ Z2: 71, Z3: 29 }}
                    label="Time in zone · this run"
                    anchored
                />
            ),
        },
        {
            name: 'no heart rate',
            render: () => <TimeInZoneBar zones={{}} />,
        },
    ],
} satisfies CatalogueEntry;
