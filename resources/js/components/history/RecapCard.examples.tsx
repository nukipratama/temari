import { analysis } from '@/components/catalogue/fixtures';
import RecapCard from '@/components/history/RecapCard';
import Chip from '@/components/ui/Chip';
import { type CatalogueEntry } from '@/lib/catalogue';

const NO_POLLING: string[] = [];

export default {
    name: 'RecapCard',
    description:
        "Temari's recap of a week or a month: the period's chips, then the narration, with Temari posed to the period's mood behind it.",
    usage: `<RecapCard mood={week.mood} analysis={week.recap_analysis} fallback={week.fallback} chips={chips} />`,
    states: [
        {
            name: 'week, narrated',
            render: () => (
                <RecapCard
                    mood="easy"
                    analysis={analysis()}
                    inertiaReloadProps={NO_POLLING}
                    chips={
                        <>
                            <Chip>4 runs</Chip>
                            <Chip tone="positive">holding</Chip>
                        </>
                    }
                />
            ),
        },
        {
            name: 'in-progress week, rule-based fallback',
            render: () => (
                <RecapCard
                    mood="gassed"
                    analysis={analysis({ status: 'pending', content: null })}
                    inertiaReloadProps={NO_POLLING}
                    awaitingSchedule
                    fallback="two runs in, both on the heavy side."
                />
            ),
        },
        {
            name: 'month, writing',
            render: () => (
                <RecapCard
                    mood="chill"
                    size="month"
                    analysis={analysis({
                        type: 'monthly_recap',
                        status: 'processing',
                        content: null,
                    })}
                    inertiaReloadProps={NO_POLLING}
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
