import { useState } from 'react';

import SessionsDial from '@/components/onboarding/SessionsDial';
import { type CatalogueEntry } from '@/lib/catalogue';

const OPTIONS = [2, 3, 4, 5, 6] as const;

function Demo({ initial }: Readonly<{ initial: number | null }>) {
    const [value, setValue] = useState(initial);

    return <SessionsDial options={OPTIONS} value={value} onChange={setValue} />;
}

export default {
    name: 'SessionsDial',
    description:
        'Sessions per week as ascending bars that fill up to the choice, so it reads as a dial rather than a button row.',
    usage: `<SessionsDial options={[2, 3, 4, 5, 6]} value={sessions} onChange={setSessions} />`,
    states: [
        { name: 'four chosen', render: () => <Demo initial={4} /> },
        { name: 'nothing chosen', render: () => <Demo initial={null} /> },
    ],
} satisfies CatalogueEntry;
