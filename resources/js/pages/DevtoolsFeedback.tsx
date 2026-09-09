import { Head } from '@inertiajs/react';

import DataTable, { Td } from '@/components/ui/DataTable';
import EmptyPanel from '@/components/ui/EmptyPanel';
import { Icon } from '@/components/ui/Icon';
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
}

const COLUMNS = ['when', 'runner', 'subject', 'reason', 'note'];
const NOTE_TRUNCATE_LENGTH = 60;

export default function DevtoolsFeedback({
    rows,
}: Readonly<{ rows: FeedbackFlagRow[] }>) {
    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title="Feedback · Devtools" />

            <header className="border-b border-border bg-popover">
                <div className="mx-auto flex max-w-page items-center justify-between px-6 py-4 2xl:max-w-page-2xl">
                    <div className="flex items-center gap-3">
                        <span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-leaf-deep text-cream">
                            <Icon
                                icon="mdi:flag-outline"
                                width={20}
                                aria-hidden
                            />
                        </span>
                        <div>
                            <h1 className="font-serif italic text-headline-xs text-foreground">
                                feedback
                            </h1>
                            <p className="text-xs text-text-3">
                                flags runners have filed, newest first.
                            </p>
                        </div>
                    </div>
                    <a
                        href="/devtools"
                        className="focus-ring hidden rounded-full px-2 py-1 text-label-micro font-semibold text-text-3 transition hover:text-foreground sm:inline"
                    >
                        Temari · Devtools
                    </a>
                </div>
            </header>

            <PageContainer>
                <DataTable
                    icon="mdi:flag-outline"
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

function truncateNote(note: string | null): string {
    if (note === null || note === '') {
        return '—';
    }

    return note.length > NOTE_TRUNCATE_LENGTH
        ? `${note.slice(0, NOTE_TRUNCATE_LENGTH)}…`
        : note;
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
            <Td>{row.reason ?? '—'}</Td>
            <td
                className="max-w-[240px] truncate px-5 py-3 text-text-2"
                title={row.note ?? undefined}
            >
                {truncateNote(row.note)}
            </td>
        </>
    );
}
