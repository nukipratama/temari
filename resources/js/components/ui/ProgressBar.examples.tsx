import ProgressBar from '@/components/ui/ProgressBar';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'ProgressBar',
    description:
        'A thin fill bar for a ratio from 0 to 1, clamped. md is the paper bar, sm the dense one.',
    usage: `<ProgressBar value={spent / ceiling} ariaLabel="spend against the ceiling" />`,
    states: [
        {
            name: 'part way',
            render: () => <ProgressBar value={0.62} ariaLabel="62%" />,
        },
        {
            name: 'empty and full',
            render: () => (
                <div className="flex flex-col gap-3">
                    <ProgressBar value={0} ariaLabel="none" />
                    <ProgressBar value={1.4} ariaLabel="over" />
                </div>
            ),
        },
        {
            name: 'sm, sky fill',
            render: () => (
                <ProgressBar
                    value={0.35}
                    size="sm"
                    tone="sky"
                    ariaLabel="35%"
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
