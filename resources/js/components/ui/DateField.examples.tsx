import { useId, useState } from 'react';

import DateField from '@/components/ui/DateField';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo({ initial, min }: Readonly<{ initial: string; min?: string }>) {
    const id = useId();
    const [value, setValue] = useState(initial);

    return (
        <div className="max-w-60">
            <label htmlFor={id} className="text-label-micro text-text-3">
                race day
            </label>
            <DateField
                id={id}
                value={value}
                min={min}
                onChange={setValue}
                className="mt-1.5"
            />
        </div>
    );
}

export default {
    name: 'DateField',
    description:
        'A native date input that keeps browser validation, plus a calendar of its own on pointer devices. Touch devices get the OS picker.',
    usage: `<DateField id="race-date" value={date} min={today} onChange={setDate} required />`,
    states: [
        { name: 'empty', render: () => <Demo initial="" /> },
        { name: 'with a value', render: () => <Demo initial="2026-11-08" /> },
        {
            name: 'with a minimum',
            render: () => <Demo initial="2026-11-08" min="2026-11-01" />,
        },
    ],
} satisfies CatalogueEntry;
