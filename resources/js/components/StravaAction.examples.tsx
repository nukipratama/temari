import StravaAction from '@/components/StravaAction';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'StravaAction',
    description:
        'Gate for a manual Strava affordance: while the kill-switch is off the control is absent, not disabled.',
    usage: `<StravaAction>
    <PillButton tone="outline">sync now</PillButton>
</StravaAction>`,
    states: [
        {
            name: 'running',
            sharedProps: { stravaPaused: false },
            render: () => (
                <StravaAction>
                    <PillButton tone="outline">sync now</PillButton>
                </StravaAction>
            ),
        },
        {
            name: 'paused',
            sharedProps: { stravaPaused: true },
            render: () => (
                <StravaAction>
                    <PillButton tone="outline">sync now</PillButton>
                </StravaAction>
            ),
        },
    ],
} satisfies CatalogueEntry;
