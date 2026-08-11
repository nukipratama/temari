import type { UserRow } from '@/pages/AiUsage/types';

import EmptyState from '@/components/aiusage/EmptyState';
import DataTable, { Td } from '@/components/ui/DataTable';
import ProgressBar from '@/components/ui/ProgressBar';
import { fmt } from '@/pages/AiUsage/helpers';

const COLUMNS = ['User', 'Calls', 'Prompt', 'Completion', 'Total', 'Average'];

export default function UserTable({
    rows,
    grandTotal,
}: Readonly<{ rows: UserRow[]; grandTotal: number }>) {
    return (
        <DataTable
            icon="mdi:account-multiple"
            title="Breakdown per User"
            subtitle="Users who chat with Temari the most."
            tone="accent"
            columns={COLUMNS}
            minWidth={520}
            rows={rows}
            rowKey={(row) => row.user_id}
            emptyState={<EmptyState />}
            renderRow={(row) => <UserCells row={row} grandTotal={grandTotal} />}
        />
    );
}

function UserCells({
    row,
    grandTotal,
}: Readonly<{ row: UserRow; grandTotal: number }>) {
    const share = grandTotal > 0 ? row.total / grandTotal : 0;
    const avg = row.calls > 0 ? Math.round(row.total / row.calls) : 0;
    const label = row.user_name ?? `User #${row.user_id}`;

    return (
        <>
            <td className="px-5 py-3 font-medium text-ink">
                <div className="flex items-center gap-1.5">
                    <span>{label}</span>
                    {row.deleted && (
                        <span className="rounded-full bg-ink/5 px-1.5 py-0.5 text-[10px] font-normal text-ink-3">
                            deleted
                        </span>
                    )}
                </div>
                {row.strava_athlete_id !== null && (
                    <div className="font-mono text-xs text-ink-3">
                        Strava {row.strava_athlete_id}
                    </div>
                )}
                <ProgressBar
                    value={share}
                    size="sm"
                    ariaLabel={`${(share * 100).toFixed(1)}% of total`}
                    className="mt-1 max-w-[160px]"
                />
            </td>
            <Td>{fmt(row.calls)}</Td>
            <Td>{fmt(row.prompt)}</Td>
            <Td>{fmt(row.completion)}</Td>
            <Td className="font-semibold text-ink">{fmt(row.total)}</Td>
            <Td>{fmt(avg)}</Td>
        </>
    );
}
