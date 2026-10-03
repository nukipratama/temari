import { useState } from 'react';

import Overlay, { OverlayClose, OverlayTitle } from '@/components/ui/Overlay';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <PillButton tone="outline" size="sm" onClick={() => setOpen(true)}>
                open overlay
            </PillButton>
            <Overlay
                open={open}
                onOpenChange={setOpen}
                backdropProps={{ className: 'fixed inset-0 bg-sky/60' }}
                className="fixed inset-x-4 top-12 mx-auto max-w-sm rounded-xl bg-popover p-5 text-foreground shadow-e3"
            >
                <OverlayTitle className="font-serif text-headline-sm">
                    a bare overlay
                </OverlayTitle>
                <p className="mt-2 text-sm text-text-2">
                    the caller brings the backdrop and the popup look.
                </p>
                <OverlayClose className="focus-ring pad-chip mt-4 min-h-11 rounded-full text-sm text-text-2">
                    close
                </OverlayClose>
            </Overlay>
        </>
    );
}

export default {
    name: 'Overlay',
    description:
        'What every modal rests on: Base UI owns the focus trap, scroll lock, escape and outside press, and Back closes the topmost one.',
    usage: `<Overlay open={open} onOpenChange={setOpen} className="…">
    <OverlayTitle>…</OverlayTitle>
    <OverlayClose>close</OverlayClose>
</Overlay>`,
    states: [{ name: 'open on demand', overlay: true, render: () => <Demo /> }],
} satisfies CatalogueEntry;
