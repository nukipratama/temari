import { useState } from 'react';

import { DayCell, DayRow } from '@/components/onboarding/DayPicker';
import { type CatalogueEntry } from '@/lib/catalogue';

const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function Demo({
    initial,
    longRun,
}: Readonly<{ initial: readonly string[]; longRun: string | null }>) {
    const [active, setActive] = useState<readonly string[]>(initial);

    return (
        <DayRow
            items={DAYS.map((day) => (
                <DayCell
                    key={day}
                    label={day}
                    active={active.includes(day)}
                    longRun={day === longRun}
                    flagCandidate={longRun === null && active.includes(day)}
                    disabled={day === 'fri'}
                    onClick={() =>
                        setActive((days) =>
                            days.includes(day)
                                ? days.filter((d) => d !== day)
                                : [...days, day],
                        )
                    }
                />
            ))}
        />
    );
}

export default {
    name: 'DayPicker',
    description:
        'Training days as a stepper row: DayRow lays out DayCell toggles joined by a line. The long-run day is the big filled one with a flag.',
    usage: `<DayRow
    items={days.map((day) => (
        <DayCell key={day} label={day} active={…} longRun={…} onClick={…} />
    ))}
/>`,
    states: [
        {
            name: 'long run chosen',
            render: () => (
                <Demo initial={['tue', 'thu', 'sun']} longRun="sun" />
            ),
        },
        {
            name: 'picking the long run',
            render: () => <Demo initial={['tue', 'sat']} longRun={null} />,
        },
    ],
} satisfies CatalogueEntry;
