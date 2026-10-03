import { useState } from 'react';

import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { type CatalogueEntry } from '@/lib/catalogue';

const RANGES = ['4 weeks', '12 weeks', 'season'] as const;

function Demo({
    initial,
    size,
    disabledLast = false,
}: Readonly<{
    initial: string | null;
    size?: 'sm' | 'md';
    disabledLast?: boolean;
}>) {
    const [value, setValue] = useState(initial);

    return (
        <ToggleGroup
            value={value}
            onValueChange={setValue}
            size={size}
            aria-label="range"
        >
            {RANGES.map((range, index) => (
                <ToggleGroupItem
                    key={range}
                    value={range}
                    disabled={disabledLast && index === RANGES.length - 1}
                >
                    {range}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}

export default {
    name: 'ToggleGroup',
    description:
        'Pick one: filters, ranges, a reason. Pressing the chosen item again keeps it; sm is inline, md the 44px touch size.',
    usage: `<ToggleGroup value={range} onValueChange={setRange} aria-label="range">
    <ToggleGroupItem value="4w">4 weeks</ToggleGroupItem>
    <ToggleGroupItem value="12w">12 weeks</ToggleGroupItem>
</ToggleGroup>`,
    states: [
        { name: 'sm', render: () => <Demo initial="12 weeks" /> },
        { name: 'md', render: () => <Demo initial="4 weeks" size="md" /> },
        { name: 'nothing chosen', render: () => <Demo initial={null} /> },
        {
            name: 'disabled item',
            render: () => <Demo initial="4 weeks" disabledLast />,
        },
    ],
} satisfies CatalogueEntry;
