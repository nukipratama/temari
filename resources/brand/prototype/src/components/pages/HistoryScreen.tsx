import {
    BellPlus,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Sparkle,
} from 'lucide-react';
import { useState, type CSSProperties } from 'react';

import { FaceIcon } from '@/components/FaceIcon';
import { cn } from '@/lib/utils';

type Mood = 'blazing' | 'easy' | 'wobbly' | 'gassed' | 'overloaded' | 'chill';
type Rarity = 'common' | 'uncommon' | 'rare' | 'epic' | 'legendary';

const moodVar = (m: Mood) => `var(--mood-${m})`;
const rarityVar = (r: Rarity) => `var(--rarity-${r})`;

interface Run {
    mood: Mood;
    name: string;
    dist: string;
    rarity?: Rarity;
    date: string;
    time: string;
    pace: string;
    hr: string;
    note: string;
}

interface Week {
    key: string;
    title: string;
    meta: string;
    mood: Mood;
    line: string;
    chips: string[];
    runs: Run[];
}

const CURRENT_WEEK: Week = {
    key: 'current',
    title: 'this week',
    meta: '3 runs · 24.6 km · trimp 186',
    mood: 'easy',
    line: "tempo tuesday was your strongest push yet, and sunday's long run held pace deep into the second hour.",
    chips: ['fatigue moderate', 'fitness +2.1'],
    runs: [
        {
            mood: 'blazing',
            name: 'tempo tuesday',
            dist: '8.4 km',
            rarity: 'rare',
            date: '12 aug · 6:12am',
            time: '42:18',
            pace: '5:02/km',
            hr: '152 bpm',
            note: 'you held that pace even when the hill fought back.',
        },
        {
            mood: 'easy',
            name: 'easy shakeout',
            dist: '5.0 km',
            date: '14 aug · 6:40am',
            time: '31:10',
            pace: '6:14/km',
            hr: '138 bpm',
            note: 'nice and loose — exactly what an easy day should feel like.',
        },
        {
            mood: 'wobbly',
            name: 'sunday long run',
            dist: '16.2 km',
            date: '16 aug · 7:05am',
            time: '1:34:02',
            pace: '5:48/km',
            hr: '149 bpm',
            note: "your longest run in five weeks, and it didn't even feel like it.",
        },
    ],
};

const LAST_WEEK: Week = {
    key: 'last',
    title: 'last week',
    meta: '4 runs · 31.2 km · trimp 210',
    mood: 'blazing',
    line: 'hill repeats tested you monday, and half marathon PR settled the score by thursday — a new PR by 2 minutes.',
    chips: ['load high', 'fitness +3.4'],
    runs: [
        {
            mood: 'gassed',
            name: 'hill repeats',
            dist: '6.1 km',
            date: '4 aug · 6:00am',
            time: '38:50',
            pace: '6:22/km',
            hr: '141 bpm',
            note: "the hills didn't let up, and neither did you.",
        },
        {
            mood: 'blazing',
            name: 'half marathon PR',
            dist: '21.1 km',
            rarity: 'epic',
            date: '7 aug · 7:00am',
            time: '1:38:20',
            pace: '4:39/km',
            hr: '158 bpm',
            note: 'new pr by 2 minutes — you paced this one perfectly.',
        },
        {
            mood: 'chill',
            name: 'recovery jog',
            dist: '4.0 km',
            date: '9 aug · 7:30am',
            time: '26:40',
            pace: '6:40/km',
            hr: '128 bpm',
            note: 'slow enough to actually recover — good instinct.',
        },
        {
            mood: 'wobbly',
            name: 'tempo intervals',
            dist: '7.2 km',
            date: '5 aug · 6:15am',
            time: '36:05',
            pace: '5:00/km',
            hr: '161 bpm',
            note: 'held the target pace across every rep.',
        },
    ],
};

const OLDER_WEEK: Week = {
    key: 'older',
    title: '27 jul – 2 aug',
    meta: '2 runs · 19.5 km · trimp 158',
    mood: 'overloaded',
    line: "a lighter week overall — monday eased you in, and saturday's long run tested legs that hadn't fully recovered.",
    chips: ['load moderate', 'fitness +1.2'],
    runs: [
        {
            mood: 'easy',
            name: 'monday easy',
            dist: '5.5 km',
            date: '28 jul · 6:20am',
            time: '33:00',
            pace: '6:00/km',
            hr: '135 bpm',
            note: 'settled into an easy rhythm early.',
        },
        {
            mood: 'overloaded',
            name: 'saturday long run',
            dist: '14.0 km',
            date: '2 aug · 7:00am',
            time: '1:22:30',
            pace: '5:53/km',
            hr: '148 bpm',
            note: 'legs felt heavy from the start, but you finished it.',
        },
    ],
};

interface DayCell {
    day: number;
    out?: boolean;
    today?: boolean;
    mood?: Mood;
}

interface WeekBlock {
    key: string;
    km?: string;
    mood?: Mood;
    days: DayCell[];
    narration?: {
        line: string;
        chips: string[];
        kartu?: { rarity: Rarity; label: string };
    };
}

interface Month {
    label: string;
    meta: string;
    mood: Mood;
    line: string;
    chips: string[];
    weeks: WeekBlock[];
    prevDisabled: boolean;
    nextDisabled: boolean;
}

const AUG_MONTH: Month = {
    label: 'august 2026',
    meta: '9 runs · 75.3 km · trimp 554',
    mood: 'blazing',
    line: 'august leaned into big efforts — a half marathon pr mid-month, backed by two kartu pulls, and fitness kept climbing even through the heavier weeks.',
    chips: ['load high', 'fitness +6.7'],
    prevDisabled: false,
    nextDisabled: true,
    weeks: [
        {
            key: 'wk1',
            km: '19.5k',
            mood: 'overloaded',
            days: [
                { day: 27, out: true },
                { day: 28, out: true, mood: 'easy' },
                { day: 29, out: true },
                { day: 30, out: true },
                { day: 31, out: true },
                { day: 1 },
                { day: 2, mood: 'overloaded' },
            ],
            narration: { line: OLDER_WEEK.line, chips: OLDER_WEEK.chips },
        },
        {
            key: 'wk2',
            km: '31.2k',
            mood: 'blazing',
            days: [
                { day: 3 },
                { day: 4, mood: 'gassed' },
                { day: 5, mood: 'wobbly' },
                { day: 6 },
                { day: 7, mood: 'blazing' },
                { day: 8 },
                { day: 9, mood: 'chill' },
            ],
            narration: {
                line: LAST_WEEK.line,
                chips: LAST_WEEK.chips,
                kartu: { rarity: 'epic', label: 'epic kartu' },
            },
        },
        {
            key: 'wk3',
            km: '24.6k',
            mood: 'easy',
            days: [
                { day: 10 },
                { day: 11 },
                { day: 12, mood: 'blazing' },
                { day: 13 },
                { day: 14, mood: 'easy' },
                { day: 15 },
                { day: 16, today: true, mood: 'wobbly' },
            ],
            narration: {
                line: CURRENT_WEEK.line,
                chips: CURRENT_WEEK.chips,
                kartu: { rarity: 'rare', label: 'rare kartu' },
            },
        },
        {
            key: 'wk4',
            days: [
                { day: 17 },
                { day: 18 },
                { day: 19 },
                { day: 20 },
                { day: 21 },
                { day: 22 },
                { day: 23 },
            ],
        },
        {
            key: 'wk5',
            days: [
                { day: 24 },
                { day: 25 },
                { day: 26 },
                { day: 27 },
                { day: 28 },
                { day: 29 },
                { day: 30 },
            ],
        },
        {
            key: 'wk6',
            days: [
                { day: 31 },
                { day: 1, out: true },
                { day: 2, out: true },
                { day: 3, out: true },
                { day: 4, out: true },
                { day: 5, out: true },
                { day: 6, out: true },
            ],
        },
    ],
};

const JUL_MONTH: Month = {
    label: 'july 2026',
    meta: '1 run · 5.5 km · trimp 48',
    mood: 'easy',
    line: 'july stayed light — a loosen-up run bridged you into a bigger august.',
    chips: ['load low', 'fitness +0.4'],
    prevDisabled: true,
    nextDisabled: false,
    weeks: [
        {
            key: 'wk1',
            days: [
                { day: 29, out: true },
                { day: 30, out: true },
                { day: 1 },
                { day: 2 },
                { day: 3 },
                { day: 4 },
                { day: 5 },
            ],
        },
        {
            key: 'wk2',
            days: [
                { day: 6 },
                { day: 7 },
                { day: 8 },
                { day: 9 },
                { day: 10 },
                { day: 11 },
                { day: 12 },
            ],
        },
        {
            key: 'wk3',
            days: [
                { day: 13 },
                { day: 14 },
                { day: 15 },
                { day: 16 },
                { day: 17 },
                { day: 18 },
                { day: 19 },
            ],
        },
        {
            key: 'wk4',
            days: [
                { day: 20 },
                { day: 21 },
                { day: 22 },
                { day: 23 },
                { day: 24 },
                { day: 25 },
                { day: 26 },
            ],
        },
        {
            key: 'wk5',
            km: '19.5k',
            mood: 'overloaded',
            days: [
                { day: 27 },
                { day: 28, mood: 'easy' },
                { day: 29 },
                { day: 30 },
                { day: 31 },
                { day: 1, out: true },
                { day: 2, out: true, mood: 'overloaded' },
            ],
            narration: { line: OLDER_WEEK.line, chips: OLDER_WEEK.chips },
        },
    ],
};

const MOOD_LEGEND: Mood[] = [
    'blazing',
    'easy',
    'wobbly',
    'gassed',
    'overloaded',
    'chill',
];

function RecapCard({
    mood,
    line,
    chips,
    size = 'week',
}: Readonly<{
    mood: Mood;
    line: string;
    chips: string[];
    size?: 'week' | 'month';
}>) {
    return (
        <div
            className={cn(
                'mb-2.5 flex items-start gap-2.5 rounded-[14px] border border-border-strong bg-card shadow-e1',
                size === 'week' ? 'p-3' : 'p-3.5',
            )}
            style={{ '--mood': moodVar(mood) } as CSSProperties}
        >
            <FaceIcon
                size={36}
                ring="var(--mood)"
                fill="var(--sky-2)"
                feature="var(--cream)"
            />
            <div className="min-w-0 flex-1">
                <p
                    className={cn(
                        'm-0 font-serif leading-[1.45] text-foreground italic',
                        size === 'week' ? 'text-xs' : 'text-[12.5px]',
                    )}
                >
                    {line}
                </p>
                <div className="mt-1.75 flex items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-1.5">
                        {chips.map((c) => (
                            <span
                                key={c}
                                className="rounded-full bg-muted px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] text-foreground uppercase"
                            >
                                {c}
                            </span>
                        ))}
                    </div>
                    <button
                        type="button"
                        className="flex size-6 flex-none items-center justify-center rounded-full bg-muted text-icon-accent"
                    >
                        <BellPlus className="size-[13px]" aria-hidden />
                    </button>
                </div>
            </div>
        </div>
    );
}

function RunRow({ run }: Readonly<{ run: Run }>) {
    return (
        <div className="border-b border-border-strong p-3.5 last:border-b-0">
            <div className="flex items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-1.5">
                    <span
                        className="size-[7px] flex-none rounded-full bg-[var(--mood)]"
                        style={{ '--mood': moodVar(run.mood) } as CSSProperties}
                    />
                    <span className="overflow-hidden text-[13px] leading-[1.2] font-bold text-ellipsis whitespace-nowrap text-foreground">
                        {run.name}
                    </span>
                    <span className="flex-none font-mono text-[13px] leading-[1.2] font-bold text-foreground">
                        · {run.dist}
                    </span>
                    {run.rarity && (
                        <Sparkle
                            className="size-3 flex-none"
                            style={{ color: rarityVar(run.rarity) }}
                            aria-hidden
                        />
                    )}
                </div>
                <span className="flex-none font-mono text-[9.5px] leading-[1.2] text-foreground">
                    {run.date}
                </span>
            </div>
            <div className="mt-1.25 flex items-baseline gap-1.75 font-mono">
                <b className="text-[13px] leading-[1.2] font-extrabold text-foreground">
                    {run.time}
                </b>
                <span className="text-[11px] text-border-strong">·</span>
                <b className="text-[13px] leading-[1.2] font-extrabold text-foreground">
                    {run.pace}
                </b>
                <span className="text-[11px] text-border-strong">·</span>
                <span className="text-[13px] leading-[1.2] font-extrabold text-foreground">
                    {run.hr}
                </span>
            </div>
            <p className="mt-1.25 overflow-hidden font-serif text-[10.5px] leading-[1.2] text-ellipsis whitespace-nowrap text-foreground italic">
                &quot;{run.note}&quot;
            </p>
        </div>
    );
}

function WeekSection({
    week,
    className,
}: Readonly<{ week: Week; className?: string }>) {
    return (
        <div className={cn('mb-5.5', className)}>
            <div className="mb-2.5 flex items-baseline justify-between px-0.5">
                <div className="font-serif text-base font-semibold text-foreground">
                    {week.title}
                </div>
                <div className="font-mono text-[9.5px] leading-[1.2] text-foreground">
                    {week.meta}
                </div>
            </div>
            <RecapCard mood={week.mood} line={week.line} chips={week.chips} />
            <div className="overflow-hidden rounded-[14px] border border-border-strong bg-card shadow-e1">
                {week.runs.map((r) => (
                    <RunRow key={r.name} run={r} />
                ))}
            </div>
        </div>
    );
}

function NoRunsCard() {
    return (
        <div className="mb-4.5 flex items-center gap-3.5 rounded-[14px] border border-border-strong bg-card p-4.5 shadow-e1">
            <FaceIcon
                size={40}
                ring="var(--horizon)"
                fill="var(--card)"
                feature="var(--foreground)"
            />
            <div>
                <p className="m-0 font-serif text-base leading-[1.2] font-semibold text-foreground italic">
                    no runs yet.
                </p>
                <p className="mt-1 text-xs leading-[1.5] text-foreground">
                    log your first run and temari will start telling the story
                    of your weeks here.
                </p>
            </div>
        </div>
    );
}

function FeedView({
    historyState,
}: Readonly<{ historyState: 'populated' | 'partial' | 'empty' }>) {
    const [olderRevealed, setOlderRevealed] = useState(false);

    if (historyState === 'empty') {
        return <NoRunsCard />;
    }

    return (
        <div>
            {historyState === 'populated' && (
                <WeekSection week={CURRENT_WEEK} />
            )}
            <WeekSection week={LAST_WEEK} />

            {!olderRevealed && (
                <div className="mb-4.5 flex justify-center">
                    <button
                        type="button"
                        onClick={() => setOlderRevealed(true)}
                        className="inline-flex items-center gap-1.25 rounded-full border border-border-strong bg-card px-4.5 py-2.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase shadow-e1"
                    >
                        load older weeks
                        <ChevronDown className="size-3" aria-hidden />
                    </button>
                </div>
            )}

            {olderRevealed && (
                <WeekSection week={OLDER_WEEK} className="mb-1" />
            )}
        </div>
    );
}

function WeekRow({ block }: Readonly<{ block: WeekBlock }>) {
    const [expanded, setExpanded] = useState(false);
    const disabled = !block.narration;

    return (
        <div className="mb-1">
            <div className="grid grid-cols-[30px_repeat(7,1fr)] gap-0.75">
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => setExpanded((e) => !e)}
                    className={cn(
                        'flex w-full flex-col items-center justify-center gap-0.5 rounded-[6px] border border-border-strong bg-card px-0.25 py-1.25 font-mono text-foreground',
                        disabled ? 'text-border-strong' : 'cursor-pointer',
                    )}
                >
                    <span className="text-[7px] leading-[1.2] font-extrabold uppercase">
                        {block.key.replace(/(\d)/, ' $1')}
                    </span>
                    {block.km && (
                        <>
                            <span className="text-[8px] leading-[1.2] font-bold text-foreground">
                                {block.km}
                            </span>
                            <span className="mt-0.25 flex items-center gap-0.5">
                                <span
                                    className="size-[5px] rounded-full bg-[var(--mood)]"
                                    style={
                                        {
                                            '--mood': moodVar(block.mood!),
                                        } as CSSProperties
                                    }
                                />
                                <ChevronDown
                                    className={cn(
                                        'size-[7px] transition-transform',
                                        expanded && 'rotate-180',
                                    )}
                                    aria-hidden
                                />
                            </span>
                        </>
                    )}
                </button>
                {block.days.map((d, i) => (
                    <div
                        key={`${d.day}-${i}`}
                        className={cn(
                            'flex min-h-8 flex-col items-center justify-center gap-0.5 rounded-[6px] border border-border-strong bg-card py-1.5 font-mono text-[9.5px] leading-[1.2] font-bold text-foreground',
                            d.out && 'opacity-32',
                            d.today &&
                                'border-horizon-ink bg-[color-mix(in_oklab,var(--horizon)_18%,var(--card))] text-foreground',
                        )}
                    >
                        {d.day}
                        {d.mood && (
                            <span
                                className="size-[5px] rounded-full bg-[var(--mood)]"
                                style={
                                    {
                                        '--mood': moodVar(d.mood),
                                    } as CSSProperties
                                }
                            />
                        )}
                    </div>
                ))}
            </div>
            {expanded && block.narration && (
                <div className="mt-1 mb-2 rounded-[10px] bg-muted px-3 py-2.5">
                    <p className="m-0 font-serif text-[11.5px] leading-[1.45] text-foreground italic">
                        &quot;{block.narration.line}&quot;
                    </p>
                    <div className="mt-1.75 flex flex-wrap gap-1.5">
                        {block.narration.chips.map((c) => (
                            <span
                                key={c}
                                className="rounded-full bg-card px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] text-foreground uppercase"
                            >
                                {c}
                            </span>
                        ))}
                        {block.narration.kartu && (
                            <span
                                className="flex items-center gap-0.5 rounded-full bg-card px-1.75 py-0.5 font-mono text-[8px] leading-[1.2] font-extrabold tracking-[.03em] uppercase"
                                style={{
                                    color: rarityVar(
                                        block.narration.kartu.rarity,
                                    ),
                                }}
                            >
                                <Sparkle className="size-2.5" aria-hidden />
                                {block.narration.kartu.label}
                            </span>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function CalendarView() {
    const [month, setMonth] = useState<'aug' | 'jul'>('aug');
    const data = month === 'aug' ? AUG_MONTH : JUL_MONTH;

    return (
        <div>
            <div className="mb-2.5 flex items-center justify-between">
                <button
                    type="button"
                    aria-label="Previous month"
                    disabled={data.prevDisabled}
                    onClick={() => setMonth('jul')}
                    className="flex size-7 items-center justify-center rounded-full bg-card text-foreground shadow-e1 disabled:opacity-35"
                >
                    <ChevronLeft className="size-4" aria-hidden />
                </button>
                <div className="font-serif text-[15px] leading-[1.2] font-semibold text-foreground">
                    {data.label}
                </div>
                <button
                    type="button"
                    aria-label="Next month"
                    disabled={data.nextDisabled}
                    onClick={() => setMonth('aug')}
                    className="flex size-7 items-center justify-center rounded-full bg-card text-foreground shadow-e1 disabled:opacity-35"
                >
                    <ChevronRight className="size-4" aria-hidden />
                </button>
            </div>

            <div className="mb-2.5 text-center font-mono text-[9.5px] leading-[1.2] text-foreground">
                {data.meta}
            </div>

            <RecapCard
                mood={data.mood}
                line={data.line}
                chips={data.chips}
                size="month"
            />

            <div className="mb-3.5 flex flex-wrap gap-x-3 gap-y-1.75 px-0.5">
                {MOOD_LEGEND.map((m) => (
                    <div
                        key={m}
                        className="flex items-center gap-1 font-mono text-[8px] leading-[1.2] font-bold tracking-[.03em] text-foreground uppercase"
                    >
                        <span
                            className="size-1.5 flex-none rounded-full bg-[var(--mood)]"
                            style={{ '--mood': moodVar(m) } as CSSProperties}
                        />
                        {m}
                    </div>
                ))}
            </div>

            <div className="mb-1.5 grid grid-cols-[30px_repeat(7,1fr)] gap-0.75">
                {['', 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'].map((d, i) => (
                    <span
                        key={d || 'wk'}
                        className={cn(
                            'text-center font-mono text-[7.5px] leading-[1.2] font-extrabold text-foreground uppercase',
                            i === 0 && 'invisible',
                        )}
                    >
                        {d}
                    </span>
                ))}
            </div>

            {data.weeks.map((w) => (
                <WeekRow key={w.key} block={w} />
            ))}
        </div>
    );
}

export function HistoryScreen({
    historyState,
}: Readonly<{ historyState: 'populated' | 'partial' | 'empty' }>) {
    const [tab, setTab] = useState<'feed' | 'calendar'>('feed');

    return (
        <div className="px-4 pt-16 pb-22 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-24">
            <div className="font-mono text-[10px] leading-[1.2] font-bold tracking-[.12em] text-foreground uppercase">
                history · 42 activities
            </div>
            <h1 className="mt-2 mb-4 font-serif text-[28px] leading-[1.1] font-semibold text-foreground italic">
                every run
                <br />
                <em className="text-icon-accent italic">has a story.</em>
            </h1>

            <nav className="mb-3.5 flex gap-1 rounded-full bg-muted p-1">
                <button
                    type="button"
                    onClick={() => setTab('feed')}
                    className={cn(
                        'flex-1 rounded-full py-2 text-center text-[11.5px] leading-[1.2] font-bold',
                        tab === 'feed'
                            ? 'bg-card text-foreground shadow-e1'
                            : 'text-foreground',
                    )}
                >
                    feed
                </button>
                <button
                    type="button"
                    onClick={() => setTab('calendar')}
                    className={cn(
                        'flex-1 rounded-full py-2 text-center text-[11.5px] leading-[1.2] font-bold',
                        tab === 'calendar'
                            ? 'bg-card text-foreground shadow-e1'
                            : 'text-foreground',
                    )}
                >
                    calendar
                </button>
            </nav>

            {tab === 'feed' ? (
                <FeedView historyState={historyState} />
            ) : (
                <CalendarView />
            )}
        </div>
    );
}
