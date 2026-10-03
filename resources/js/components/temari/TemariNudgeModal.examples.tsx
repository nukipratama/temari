import { Flag } from 'lucide-react';
import { useState } from 'react';

import TemariNudgeModal from '@/components/temari/TemariNudgeModal';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <PillButton tone="outline" size="sm" onClick={() => setOpen(true)}>
                open the nudge
            </PillButton>
            <TemariNudgeModal
                open={open}
                onClose={() => setOpen(false)}
                title="set a race first"
                body="a plan needs something to build towards. pick a race and a date, and the weeks follow."
                primaryLabel="set a race"
                primaryIcon={Flag}
                onPrimary={() => setOpen(false)}
            />
        </>
    );
}

export default {
    name: 'TemariNudgeModal',
    description:
        "Temari's soft front door: a calm nudge with a title, a short body, a primary action and a dismiss. The dialog loads on the first open.",
    usage: `<TemariNudgeModal
    open={open}
    onClose={close}
    title="set a race first"
    body="…"
    primaryLabel="set a race"
    primaryIcon={Flag}
    onPrimary={goToRace}
/>`,
    states: [{ name: 'calm nudge', overlay: true, render: () => <Demo /> }],
} satisfies CatalogueEntry;
