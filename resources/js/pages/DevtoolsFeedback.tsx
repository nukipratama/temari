import { Head } from '@inertiajs/react';
import { Flag } from 'lucide-react';

import DataTable, { Td } from '@/components/narration/DataTable';
import DevtoolsHeader from '@/components/narration/DevtoolsHeader';
import EmptyPanel from '@/components/ui/EmptyPanel';
import PageContainer from '@/components/ui/PageContainer';

export interface FeedbackFlagRow {
    id: number;
    created_at: string;
    created_at_full: string;
    runner: string;
    subject_label: string;
    subject_url: string | null;
    reason: string | null;
    note: string | null;
    superseded: boolean;
}

const COLUMNS = ['when', 'runner', 'subject', 'reason', 'note'];

export default function DevtoolsFeedback({
    rows,
}: Readonly<{ rows: FeedbackFlagRow[] }>) {
    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title="Feedback · Devtools" />

            <DevtoolsHeader icon={Flag} title="feedback">
                <p className="text-xs text-text-3">
                    flags runners have filed, newest first.
                </p>
            </DevtoolsHeader>

            <PageContainer className="min-[900px]:max-w-page min-[1280px]:max-w-page 2xl:max-w-page-2xl">
                <DataTable
                    icon={Flag}
                    title="flagged"
                    subtitle="last 200 flags."
                    tone="accent"
                    columns={COLUMNS}
                    minWidth={640}
                    rows={rows}
                    rowKey={(row) => row.id}
                    emptyState={
                        <EmptyPanel
                            title="no flags yet"
                            body="nobody has flagged a plan day or a narration as wrong."
                        />
                    }
                    renderRow={(row) => <FeedbackCells row={row} />}
                />
            </PageContainer>
        </div>
    );
}

function FeedbackCells({ row }: Readonly<{ row: FeedbackFlagRow }>) {
    return (
        <>
            <td className="px-5 py-3 text-text-3" title={row.created_at_full}>
                {row.created_at}
            </td>
            <Td className="font-medium text-foreground">{row.runner}</Td>
            <Td>
                {row.subject_url ? (
                    <a
                        href={row.subject_url}
                        className="focus-ring text-leaf-ink underline underline-offset-2"
                    >
                        {row.subject_label}
                    </a>
                ) : (
                    row.subject_label
                )}
            </Td>
            <Td>
                {row.reason ?? '—'}
                {row.superseded && (
                    <span className="block text-label-micro text-text-3">
                        superseded
                    </span>
                )}
            </Td>
            <td
                className="max-w-[40ch] whitespace-normal px-5 py-3 text-text-2"
                title={row.note ?? undefined}
            >
                {row.note ?? '—'}
            </td>
        </>
    );
}
