import {
    type ReactNode,
    type RefObject,
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';

import type { IconComponent } from '@/components/ui/Icon';

import SectionHeading from '@/components/SectionHeading';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/cn';

interface DataTableProps<T> {
    icon: IconComponent;
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
    const scrollerRef = useRef<HTMLDivElement>(null);
    const moreToTheRight = useMoreToTheRight(scrollerRef, rows);

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
                        ref={scrollerRef}
                        className="overflow-x-auto bg-popover py-0 scrollbar-thin-fine"
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
                    {/* A sibling of the overflow-x-auto card, not a descendant: a descendant
                        scrolls away with the table instead of staying pinned to the edge. */}
                    {moreToTheRight && (
                        <div
                            aria-hidden
                            data-testid="table-scroll-hint"
                            className="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-popover to-transparent"
                        />
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * ResizeObserver delivers once on observe(), which is what takes the initial
 * measurement without a synchronous setState in the effect.
 */
function useMoreToTheRight(
    scrollerRef: RefObject<HTMLDivElement | null>,
    rows: readonly unknown[],
): boolean {
    const [moreToTheRight, setMoreToTheRight] = useState(false);

    const measure = useCallback(() => {
        const node = scrollerRef.current;
        setMoreToTheRight(
            node !== null &&
                node.scrollWidth - node.clientWidth - node.scrollLeft > 1,
        );
    }, [scrollerRef]);

    useEffect(() => {
        const node = scrollerRef.current;
        if (node === null) {
            return;
        }

        node.addEventListener('scroll', measure, { passive: true });
        const observer = new ResizeObserver(measure);
        observer.observe(node);

        return () => {
            node.removeEventListener('scroll', measure);
            observer.disconnect();
        };
    }, [measure, scrollerRef, rows]);

    return moreToTheRight;
}

export function Td({
    children,
    className,
}: Readonly<{ children: ReactNode; className?: string }>) {
    return (
        <td className={cn('px-5 py-3 text-text-2', className)}>{children}</td>
    );
}
