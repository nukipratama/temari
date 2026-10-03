import { Trash2 } from 'lucide-react';
import { useState } from 'react';

import TemariNudgeDialog from '@/components/temari/TemariNudgeDialog';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <PillButton tone="outline" size="sm" onClick={() => setOpen(true)}>
                open the dialog
            </PillButton>
            <TemariNudgeDialog
                open={open}
                onClose={() => setOpen(false)}
                title="delete this race?"
                body="the plan built for it goes too. your runs stay."
                primaryLabel="delete race"
                primaryIcon={Trash2}
                primaryTone="danger"
                pose="concerned"
                onPrimary={() => setOpen(false)}
            />
        </>
    );
}

export default {
    name: 'TemariNudgeDialog',
    description:
        "TemariNudgeModal's dialog itself, loaded on its first open. Mount TemariNudgeModal, not this.",
    usage: `<TemariNudgeModal … />`,
    states: [
        {
            name: 'destructive confirmation',
            overlay: true,
            render: () => <Demo />,
        },
    ],
} satisfies CatalogueEntry;
