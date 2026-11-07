import HardMinuteWallet from '@/components/plan/HardMinuteWallet';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'HardMinuteWallet',
    description:
        "The current week's hard minutes against the cap locked at its first regeneration: spent, booked on the days ahead, and what is left or over.",
    usage: `<HardMinuteWallet budget={week.hard_budget} />`,
    states: [
        {
            name: 'room left',
            render: () => (
                <HardMinuteWallet
                    budget={{
                        cap_minutes: 60,
                        spent_minutes: 29,
                        booked_minutes: 15,
                        left_minutes: 16,
                    }}
                />
            ),
        },
        {
            name: 'over the cap',
            render: () => (
                <HardMinuteWallet
                    budget={{
                        cap_minutes: 60,
                        spent_minutes: 60,
                        booked_minutes: 15,
                        left_minutes: -15,
                    }}
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
