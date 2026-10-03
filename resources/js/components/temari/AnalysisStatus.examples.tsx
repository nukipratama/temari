import { analysis, RUNNER } from '@/components/catalogue/fixtures';
import AnalysisStatus from '@/components/temari/AnalysisStatus';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'AnalysisStatus',
    description:
        "Every narrated block's lifecycle in one place: the text when done, a skeleton while queued, an honest note and a retry when failed, and nothing for a plain pending block.",
    usage: `<AnalysisStatus analysis={run.insight} inertiaReloadProps={['insight']} />`,
    states: [
        {
            name: 'done',
            render: () => <AnalysisStatus analysis={analysis()} />,
        },
        {
            name: 'done, flaggable, plan since changed',
            overlay: true,
            sharedProps: { auth: { user: RUNNER } },
            render: () => (
                <AnalysisStatus
                    analysis={analysis({ id: 0, is_stale: true })}
                    allowReanalyze={false}
                />
            ),
        },
        {
            name: 'queued, with temari thinking',
            render: () => (
                <AnalysisStatus
                    analysis={analysis({ status: 'queued', content: null })}
                    thinkingMark
                />
            ),
        },
        {
            name: 'processing, second attempt',
            render: () => (
                <AnalysisStatus
                    analysis={analysis({
                        status: 'processing',
                        content: null,
                        attempts: 2,
                    })}
                    size="sm"
                />
            ),
        },
        {
            name: 'failed',
            render: () => (
                <AnalysisStatus
                    analysis={analysis({ status: 'failed', content: null })}
                />
            ),
        },
        {
            name: 'failed while narration is paused',
            sharedProps: { aiPaused: true, aiPauseRetriesFailed: true },
            render: () => (
                <AnalysisStatus
                    analysis={analysis({ status: 'failed', content: null })}
                />
            ),
        },
        {
            name: 'awaiting the scheduler',
            render: () => (
                <AnalysisStatus
                    analysis={analysis({ status: 'pending', content: null })}
                    awaitingSchedule
                />
            ),
        },
        {
            name: 'plain pending',
            render: () => (
                <AnalysisStatus
                    analysis={analysis({ status: 'pending', content: null })}
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
