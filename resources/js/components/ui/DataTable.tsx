import type { ReactNode } from 'react';

import SectionHeading from '@/components/SectionHeading';
import Card from '@/components/ui/Card';
import { cn } from '@/lib/cn';

interface DataTableProps<T> {
    icon: string;
    title: string;
    subtitle: string;
    tone: 'brand' | 'accent';
    columns: readonly string[];
    /** Floor width so the table scrolls (not clips) on mobile. */
    minWidth: number;
    rows: readonly T[];
    rowKey: (row: T) => string | number;
    renderRow: (row: T) => ReactNode;
    emptyState: ReactNode;
}

export default function DataTable<T>({
    icon,
    title,
    subtitle,
    tone,
    columns,
    minWidth,
    rows,
    rowKey,
    renderRow,
    emptyState,
}: Readonly<DataTableProps<T>>) {
    return (
        <section className="mt-10">
            <SectionHeading
                icon={icon}
                title={title}
                subtitle={subtitle}
                tone={tone}
            />

            {rows.length === 0 ? (
                emptyState
            ) : (
                <div className="relative mt-4">
                    <Card
                        tone="card"
                        padding="none"
                        className="overflow-x-auto bg-popover"
                    >
                        <table
                            className="w-full text-sm tabular-nums"
                            style={{ minWidth }}
                        >
                            <thead>
                                <tr className="border-b border-border text-left text-xs text-text-3">
                                    {columns.map((label) => (
                                        <th
                                            key={label}
                                            className="px-5 py-3 font-semibold"
                                        >
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr
                                        key={rowKey(row)}
                                        className="border-b border-border last:border-b-0"
                                    >
                                        {renderRow(row)}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                    {/* Scroll hint for narrow viewports. Must be a sibling of the
                        overflow-x-auto card, not a descendant — a descendant scrolls away
                        with the table content instead of staying pinned to the visible edge. */}
                    <div
                        aria-hidden
                        className="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-surface-elev to-transparent"
                    />
                </div>
            )}
        </section>
    );
}

export function Td({
    children,
    className,
}: Readonly<{ children: ReactNode; className?: string }>) {
    return (
        <td className={cn('px-5 py-3 text-text-2', className)}>{children}</td>
    );
}
