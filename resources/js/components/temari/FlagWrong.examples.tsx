import { DEMO_RUNNER, RUNNER } from '@/components/catalogue/fixtures';
import FlagWrong from '@/components/temari/FlagWrong';
import { type CatalogueEntry } from '@/lib/catalogue';

const signedIn = { auth: { user: RUNNER } };

export default {
    name: 'FlagWrong',
    description:
        '"This is wrong" on a narration or a plan day: one flag icon that opens FlagSheet on the first tap. Hidden for the demo and once flagged.',
    usage: `<FlagWrong subjectType="narration" subjectId={analysis.id} label="flag this read" flagged={analysis.flagged} />`,
    states: [
        {
            name: 'default',
            sharedProps: signedIn,
            overlay: true,
            render: () => (
                <FlagWrong
                    subjectType="plan_day"
                    subjectId={0}
                    label="flag this day"
                />
            ),
        },
        {
            name: 'compact, beside a line of text',
            sharedProps: signedIn,
            render: () => (
                <div className="flex items-center gap-1.5 text-xs text-text-3">
                    generated 2h ago
                    <FlagWrong
                        subjectType="narration"
                        subjectId={0}
                        label="flag this read"
                        compact
                    />
                </div>
            ),
        },
        {
            name: 'already flagged',
            sharedProps: signedIn,
            render: () => (
                <FlagWrong
                    subjectType="narration"
                    subjectId={0}
                    label="flag this read"
                    flagged
                />
            ),
        },
        {
            name: 'demo runner',
            sharedProps: { auth: { user: DEMO_RUNNER } },
            render: () => (
                <FlagWrong
                    subjectType="narration"
                    subjectId={0}
                    label="flag this read"
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
