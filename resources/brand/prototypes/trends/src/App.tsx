import { useState } from 'react';

import { ConsistencyTrend } from '@/components/sections/ConsistencyTrend';
import { FitnessTrend } from '@/components/sections/FitnessTrend';
import { LoadTrend } from '@/components/sections/LoadTrend';
import { ProgressionTrend } from '@/components/sections/ProgressionTrend';
import { RecordsPanel } from '@/components/sections/RecordsPanel';
import { VdotTrend } from '@/components/sections/VdotTrend';
import { SegmentedControl } from '@/components/SegmentedControl';
import { RANGES, type RangeKey } from '@/data/mock';
import { cn } from '@/lib/utils';

const TABS = [
    { key: 'today', label: 'Today', icon: '☀️' },
    { key: 'trends', label: 'Trends', icon: '📈' },
    { key: 'history', label: 'History', icon: '🗂️' },
    { key: 'me', label: 'Me', icon: '🙂' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

function TabBar({
    tab,
    onChange,
    className,
}: Readonly<{
    tab: TabKey;
    onChange: (t: TabKey) => void;
    className?: string;
}>) {
    return (
        <nav className={className} aria-label="Sections">
            <ul className="flex">
                {TABS.map((t) => (
                    <li key={t.key} className="flex-1">
                        <button
                            type="button"
                            aria-current={t.key === tab ? 'page' : undefined}
                            onClick={() => onChange(t.key)}
                            className={cn(
                                'flex w-full flex-col items-center gap-0.5 rounded-(--r-tile) px-3 py-2 text-[11px] font-semibold transition-colors sm:flex-row sm:gap-2 sm:text-sm',
                                t.key === tab
                                    ? 'bg-horizon/25 text-ink'
                                    : 'text-ink-3 hover:bg-cream-deep',
                            )}
                        >
                            <span aria-hidden className="text-base sm:text-sm">
                                {t.icon}
                            </span>
                            {t.label}
                        </button>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

function NotBuilt({ label }: Readonly<{ label: string }>) {
    return (
        <div className="rounded-(--r-card) border border-dashed border-line bg-card p-8 text-center">
            <p className="display text-base text-ink">
                {label} is not in this prototype.
            </p>
            <p className="mt-1 text-sm text-ink-3">
                Trends is the surface being proved first. The other three follow
                once this pattern is approved.
            </p>
        </div>
    );
}

export default function App() {
    const [tab, setTab] = useState<TabKey>('trends');
    const [range, setRange] = useState<RangeKey>('12mo');

    return (
        <div className="min-h-dvh bg-background pb-24 sm:pb-0">
            <header className="sticky top-0 z-20 border-b border-border bg-background/85 backdrop-blur">
                <div className="mx-auto flex max-w-5xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div className="flex items-baseline gap-3">
                        <span className="display text-lg text-ink">Temari</span>
                        <span className="eyebrow text-[11px] text-ink-3">
                            Trends prototype
                        </span>
                    </div>
                    <TabBar
                        tab={tab}
                        onChange={setTab}
                        className="hidden sm:block sm:w-auto"
                    />
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
                {tab !== 'trends' ? (
                    <NotBuilt label={TABS.find((t) => t.key === tab)!.label} />
                ) : (
                    <div className="flex flex-col gap-6">
                        <div className="flex flex-col gap-4">
                            <div>
                                <h1 className="display text-2xl text-ink sm:text-3xl">
                                    How things are going
                                </h1>
                                <p className="mt-1 max-w-prose text-sm text-ink-3">
                                    A year of running, read as lines rather than
                                    as a list. Everything on this page is your
                                    own history, never a comparison with anyone
                                    else.
                                </p>
                            </div>
                            <div className="flex items-center gap-3 overflow-x-auto">
                                <span className="eyebrow shrink-0 text-[11px] text-ink-3">
                                    Range
                                </span>
                                <SegmentedControl
                                    label="Time range"
                                    value={range}
                                    options={RANGES}
                                    onChange={setRange}
                                />
                            </div>
                        </div>

                        <FitnessTrend range={range} />
                        <ProgressionTrend />
                        <div className="grid items-start gap-6 lg:grid-cols-2">
                            <VdotTrend range={range} />
                            <ConsistencyTrend range={range} />
                        </div>
                        <LoadTrend range={range} />
                        <RecordsPanel />

                        <p className="pb-4 text-xs text-ink-3">
                            Prototype. Every number on this page is fixture
                            data, shaped with the same formulas the app uses but
                            not read from any database.
                        </p>
                    </div>
                )}
            </main>

            <TabBar
                tab={tab}
                onChange={setTab}
                className="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-background/95 px-2 py-1.5 backdrop-blur sm:hidden"
            />
        </div>
    );
}
