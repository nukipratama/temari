import { useState } from 'react';

import DemoBlockedModal from '@/components/DemoBlockedModal';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <PillButton tone="outline" size="sm" onClick={() => setOpen(true)}>
                open the demo nudge
            </PillButton>
            <DemoBlockedModal open={open} onClose={() => setOpen(false)} />
        </>
    );
}

export default {
    name: 'DemoBlockedModal',
    description:
        'The soft upsell a demo visitor sees on a blocked Telegram action. Its primary action signs out, which is live here too.',
    usage: `<DemoBlockedModal open={open} onClose={close} />`,
    states: [{ name: 'open on demand', overlay: true, render: () => <Demo /> }],
} satisfies CatalogueEntry;
