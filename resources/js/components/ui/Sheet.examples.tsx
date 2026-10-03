import { useState } from 'react';

import PillButton from '@/components/ui/PillButton';
import Sheet, { SheetClose } from '@/components/ui/Sheet';
import { type CatalogueEntry } from '@/lib/catalogue';

function SheetDemo() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <PillButton tone="outline" size="sm" onClick={() => setOpen(true)}>
                open sheet
            </PillButton>
            <Sheet open={open} onOpenChange={setOpen} title="tuesday, 5 km">
                <p className="mt-2 text-sm text-text-2">
                    an easy one: hold it around 7:00/km, easy enough to talk.
                </p>
                <SheetClose className="focus-ring pad-chip mt-4 min-h-11 self-start rounded-full text-sm text-text-2">
                    close
                </SheetClose>
            </Sheet>
        </>
    );
}

export default {
    name: 'Sheet',
    description:
        'A bottom sheet on Overlay: drag the grip down, press escape or Back, or tap outside to close.',
    usage: `<Sheet open={open} onOpenChange={setOpen} title="tuesday, 5 km">
    {details}
    <SheetClose>close</SheetClose>
</Sheet>`,
    states: [
        { name: 'open on demand', overlay: true, render: () => <SheetDemo /> },
    ],
} satisfies CatalogueEntry;
