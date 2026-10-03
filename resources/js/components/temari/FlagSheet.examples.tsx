import { useState } from 'react';

import type { FeedbackSubject } from '@/types/generated';

import FlagSheet from '@/components/temari/FlagSheet';
import PillButton from '@/components/ui/PillButton';
import { type CatalogueEntry } from '@/lib/catalogue';

function Demo({ subjectType }: Readonly<{ subjectType: FeedbackSubject }>) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <PillButton tone="outline" size="sm" onClick={() => setOpen(true)}>
                open the flag sheet
            </PillButton>
            <FlagSheet
                subjectType={subjectType}
                subjectId={0}
                open={open}
                onOpenChange={setOpen}
                onSent={() => undefined}
            />
        </>
    );
}

export default {
    name: 'FlagSheet',
    description:
        'The sheet FlagWrong opens: a named reason, an optional note, and "something else" holding the send until the note says what happened.',
    usage: `<FlagSheet subjectType="narration" subjectId={id} open={open} onOpenChange={setOpen} onSent={markSent} />`,
    states: [
        {
            name: 'narration',
            overlay: true,
            render: () => <Demo subjectType="narration" />,
        },
        {
            name: 'plan day',
            overlay: true,
            render: () => <Demo subjectType="plan_day" />,
        },
    ],
} satisfies CatalogueEntry;
