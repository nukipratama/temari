import {
    ArrowRight,
    Clock,
    Download,
    Flame,
    Footprints,
    Heart,
    Lightbulb,
    Loader2,
    MessageCircle,
    Mountain,
    RefreshCw,
    Scale,
    Send,
    Star,
    Timer,
    TrendingUp,
    Wind,
    Zap,
} from 'lucide-react';
import { useLayoutEffect, useRef, useState, type CSSProperties } from 'react';

import { FaceIcon } from '@/components/FaceIcon';
import { cn } from '@/lib/utils';

type Mood = 'blazing' | 'easy' | 'wobbly' | 'gassed' | 'overloaded' | 'chill';
const moodVar = (m: Mood) => `var(--mood-${m})`;

const HEADLINE_STAT = {
    value: '10.42',
    unit: 'km',
    label: 'distance',
} as const;

const SUPPORTING_STATS = [
    { icon: Timer, value: '48:32', label: 'duration' },
    { icon: Zap, value: '4:39/km', label: 'pace' },
] as const;

const SECONDARY_STATS = [
    { icon: Heart, value: '152', unit: 'bpm', label: 'hr' },
    { icon: Flame, value: '118', unit: null, label: 'trimp' },
    { icon: TrendingUp, value: '62', unit: 'm', label: 'elevation' },
] as const;

const SPLITS = [
    { km: 1, pace: '4:52', hr: 144, cadence: 172, fastest: false },
    { km: 2, pace: '4:41', hr: 149, cadence: 174, fastest: false },
    { km: 3, pace: '4:38', hr: 152, cadence: 175, fastest: false },
    { km: 4, pace: '4:35', hr: 155, cadence: 176, fastest: false },
    { km: 5, pace: '4:33', hr: 156, cadence: 177, fastest: true },
    { km: 6, pace: '4:36', hr: 157, cadence: 176, fastest: false },
    { km: 7, pace: '4:40', hr: 158, cadence: 175, fastest: false },
    { km: 8, pace: '4:44', hr: 159, cadence: 174, fastest: false },
    { km: 9, pace: '4:49', hr: 161, cadence: 173, fastest: false },
    { km: 10, pace: '4:53', hr: 162, cadence: 172, fastest: false },
] as const;
const SPLIT_PARTIAL = {
    km: '0.4',
    pace: '4:31',
    hr: 160,
    cadence: 179,
} as const;

const LAPS = [
    {
        lap: 1,
        dist: '3.2km',
        time: '14:52',
        pace: '4:39',
        hr: 149,
        cadence: 175,
        fastest: false,
    },
    {
        lap: 2,
        dist: '3.2km',
        time: '14:38',
        pace: '4:35',
        hr: 153,
        cadence: 176,
        fastest: false,
    },
    {
        lap: 3,
        dist: '3.2km',
        time: '14:41',
        pace: '4:35',
        hr: 156,
        cadence: 177,
        fastest: false,
    },
    {
        lap: 4,
        dist: '1.02km',
        time: '4:21',
        pace: '4:16',
        hr: 159,
        cadence: 179,
        fastest: true,
    },
] as const;

const SUGGESTIONS = [
    'why was my heart rate higher than usual?',
    "how's my cadence trending?",
] as const;

const QUESTIONS = [
    {
        status: 'done' as const,
        q: 'why did km 5 feel so much faster?',
        a: 'km 5 landed right after a short downhill stretch — cadence ticked up to 179 spm without you pushing harder, so the pace gain was mostly free.',
    },
    {
        status: 'pending' as const,
        q: 'how does this compare to my last tempo run?',
    },
    {
        status: 'failed' as const,
        q: "what's decoupling actually measure?",
    },
];

const STOOD_OUT_CLAIMS = [
    {
        text: 'cadence climbed through the middle laps',
        value: '176→179 spm',
        delta: '+3 spm',
    },
    {
        text: 'heart rate stayed inside zone 3 almost the whole way',
        value: 'z3 tempo',
        delta: null,
    },
    {
        text: 'the last kilometer held pace despite rising fatigue',
        value: '4:53/km',
        delta: '+0:04',
    },
] as const;

function paceToSec(pace: string) {
    const [m, s] = pace.split(':').map(Number);
    return m * 60 + s;
}

const SPLIT_HR_MIN = 140;
const SPLIT_HR_MAX = 165;
function splitHrY(hr: number) {
    return 108 - ((hr - SPLIT_HR_MIN) / (SPLIT_HR_MAX - SPLIT_HR_MIN)) * 96;
}

function HydratingNotice({ stopped }: Readonly<{ stopped: boolean }>) {
    return (
        <div className="mb-4 flex items-start gap-3 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            {stopped ? (
                <Clock
                    className="mt-0.5 size-[18px] flex-none text-foreground"
                    aria-hidden
                />
            ) : (
                <Download
                    className="mt-0.5 size-[18px] flex-none text-icon-accent"
                    aria-hidden
                />
            )}
            <div>
                <b className="block text-[12.5px] leading-[1.2] font-bold text-foreground">
                    {stopped
                        ? 'still waiting on the rest of this run'
                        : 'still filling this run in'}
                </b>
                <p className="m-0 mt-1 text-[11.5px] leading-[1.5] text-foreground">
                    {stopped
                        ? 'the deeper fetch still has not landed. we stopped reloading on your behalf rather than doing it forever, so this one is on you now.'
                        : 'so far we have the distance, time and pace strava lists for it. splits, heart-rate zones, effort score and its card come from a second, deeper fetch that can take a few minutes. this page refreshes itself when the rest arrives.'}
                </p>
                {stopped && (
                    <button
                        type="button"
                        className="mt-2.5 inline-flex items-center gap-1.5 rounded-full border border-border-strong bg-transparent px-3 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase"
                    >
                        <RefreshCw className="size-3" aria-hidden />
                        check again
                    </button>
                )}
            </div>
        </div>
    );
}

function SecondaryStatTile({
    stat,
}: Readonly<{ stat: (typeof SECONDARY_STATS)[number] }>) {
    return (
        <div className="flex items-center gap-2 rounded-[10px] bg-muted px-2.5 py-2">
            <stat.icon
                className="size-3.5 flex-none text-icon-accent"
                aria-hidden
            />
            <div className="min-w-0">
                <div className="leading-[1.2]">
                    <b className="font-mono text-[13px] font-extrabold text-foreground">
                        {stat.value}
                    </b>
                    {stat.unit && (
                        <span className="ml-0.5 font-mono text-[8px] tracking-[.03em] text-foreground uppercase">
                            {stat.unit}
                        </span>
                    )}
                </div>
                <span className="block truncate font-mono text-[7.5px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                    {stat.label}
                </span>
            </div>
        </div>
    );
}

function MapWeatherPanel() {
    return (
        <div className="mt-4 overflow-hidden rounded-[14px] bg-muted">
            <div className="relative flex h-[130px] items-center justify-center bg-muted">
                <svg
                    viewBox="0 0 200 110"
                    className="absolute inset-0 h-full w-full"
                    preserveAspectRatio="xMidYMid slice"
                >
                    <rect width="200" height="110" fill="var(--muted)" />
                    <rect
                        x="98"
                        y="42"
                        width="46"
                        height="34"
                        rx="6"
                        fill="var(--leaf)"
                        opacity=".14"
                    />
                    <g stroke="var(--foreground)" strokeLinecap="round">
                        <path d="M0,98 L200,26" strokeWidth="1" opacity=".1" />
                        <line
                            x1="0"
                            y1="16"
                            x2="200"
                            y2="16"
                            strokeWidth="1"
                            opacity=".14"
                        />
                        <line
                            x1="0"
                            y1="37"
                            x2="200"
                            y2="35"
                            strokeWidth="2.5"
                            opacity=".2"
                        />
                        <line
                            x1="0"
                            y1="61"
                            x2="200"
                            y2="63"
                            strokeWidth="1"
                            opacity=".14"
                        />
                        <line
                            x1="0"
                            y1="85"
                            x2="200"
                            y2="86"
                            strokeWidth="1"
                            opacity=".14"
                        />
                        <line
                            x1="26"
                            y1="0"
                            x2="24"
                            y2="110"
                            strokeWidth="1"
                            opacity=".14"
                        />
                        <line
                            x1="58"
                            y1="0"
                            x2="60"
                            y2="110"
                            strokeWidth="1"
                            opacity=".14"
                        />
                        <line
                            x1="93"
                            y1="0"
                            x2="91"
                            y2="110"
                            strokeWidth="2.5"
                            opacity=".2"
                        />
                        <line
                            x1="128"
                            y1="0"
                            x2="130"
                            y2="110"
                            strokeWidth="1"
                            opacity=".14"
                        />
                        <line
                            x1="163"
                            y1="0"
                            x2="161"
                            y2="110"
                            strokeWidth="1"
                            opacity=".14"
                        />
                    </g>
                    <path
                        d="M18,88 Q45,32 82,50 T156,20 T186,42"
                        stroke="var(--foreground)"
                        strokeWidth="5"
                        fill="none"
                        strokeLinecap="round"
                        opacity=".18"
                    />
                    <path
                        d="M18,88 Q45,32 82,50 T156,20 T186,42"
                        stroke="var(--horizon)"
                        strokeWidth="2.75"
                        fill="none"
                        strokeLinecap="round"
                    />
                    <circle
                        cx="18"
                        cy="88"
                        r="4"
                        fill="var(--muted)"
                        stroke="var(--horizon)"
                        strokeWidth="2"
                    />
                    <circle cx="186" cy="42" r="4.5" fill="var(--horizon)" />
                </svg>
                <span className="relative rounded-full bg-[rgba(11,16,23,.65)] px-3 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-cream uppercase">
                    activate map
                </span>
            </div>
            <div className="flex items-center gap-3 px-4 py-3">
                <div>
                    <b className="font-mono text-lg leading-[1.2] font-extrabold text-foreground">
                        24°<span className="text-xs text-foreground">c</span>
                    </b>
                    <span className="mt-0.5 block text-[9.5px] leading-[1.2] text-foreground">
                        58% humidity
                    </span>
                </div>
                <div className="flex items-center gap-1 text-[9.5px] leading-[1.2] text-foreground">
                    <Wind className="size-3" aria-hidden />9 km/h
                </div>
                <div className="ml-auto border-l border-border-strong pl-3 text-right">
                    <b className="block truncate text-[12px] leading-[1.2] font-bold text-foreground">
                        senayan
                    </b>
                    <span className="block truncate text-[9.5px] leading-[1.2] text-foreground">
                        jakarta, indonesia
                    </span>
                </div>
            </div>
        </div>
    );
}

function PastYouCard() {
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                you vs past you
            </div>
            <p className="m-0 font-mono text-2xl leading-[1.15] font-extrabold text-icon-accent">
                47{' '}
                <span className="text-sm text-foreground">sec/km faster</span>
            </p>
            <p className="m-0 mt-1 text-[11.5px] leading-[1.4] text-foreground">
                than the same 10.4 km, 21 days ago · morning easy
            </p>
            <a
                href="#"
                className="mt-2 inline-flex items-center gap-1 text-[11px] leading-[1.2] font-bold text-icon-accent no-underline"
            >
                view that run
                <ArrowRight className="size-3" aria-hidden />
            </a>

            <dl className="mt-4 grid grid-cols-2 gap-3">
                <div>
                    <dt className="font-mono text-[8.5px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                        heart rate
                    </dt>
                    <dd className="m-0 mt-0.5 text-[13px] leading-[1.2] font-bold text-leaf">
                        6 bpm lower
                    </dd>
                </div>
                <div>
                    <dt className="font-mono text-[8.5px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                        over the distance
                    </dt>
                    <dd className="m-0 mt-0.5 text-[13px] leading-[1.2] font-bold text-leaf">
                        1:52 quicker
                    </dd>
                </div>
            </dl>
        </div>
    );
}

function HeroPanel() {
    return (
        <div className="mb-4 rounded-[26px] border border-border-strong bg-card p-5 text-foreground shadow-e1">
            <div className="mb-3.5 flex items-center gap-3.5">
                <FaceIcon
                    size={56}
                    ring="var(--horizon)"
                    fill="var(--card)"
                    feature="var(--foreground)"
                />
                <div>
                    <div className="font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                        19 feb 2026 · 06:52
                    </div>
                    <h1 className="m-0 mt-1 font-serif text-[22px] leading-[1.15] font-semibold text-foreground italic">
                        morning tempo
                    </h1>
                    <span
                        className="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 font-sans text-[11px] leading-[1.2] font-bold text-foreground"
                        style={{ '--dot': moodVar('blazing') } as CSSProperties}
                    >
                        <i className="size-2 rounded-full bg-[var(--dot)]" />
                        blazing
                    </span>
                </div>
            </div>

            <div className="mt-4 flex items-end justify-between gap-3">
                <div>
                    <div className="flex items-baseline gap-1">
                        <b className="font-mono text-[34px] leading-[1] font-extrabold text-foreground">
                            {HEADLINE_STAT.value}
                        </b>
                        <span className="font-mono text-[11px] leading-[1] tracking-[.04em] text-foreground uppercase">
                            {HEADLINE_STAT.unit}
                        </span>
                    </div>
                    <span className="mt-0.5 block font-mono text-[8.5px] leading-[1.2] tracking-[.05em] text-foreground uppercase">
                        {HEADLINE_STAT.label}
                    </span>
                </div>
                <div className="flex flex-col items-end gap-1.25 pb-0.5">
                    {SUPPORTING_STATS.map((s) => (
                        <div
                            key={s.label}
                            className="flex items-center gap-1.5"
                        >
                            <span className="font-mono text-[13px] leading-[1.2] font-bold text-foreground">
                                {s.value}
                            </span>
                            <s.icon
                                className="size-3 flex-none text-icon-accent"
                                aria-hidden
                            />
                        </div>
                    ))}
                </div>
            </div>

            <div className="mt-3.5 grid grid-cols-3 gap-2">
                {SECONDARY_STATS.map((s) => (
                    <SecondaryStatTile key={s.label} stat={s} />
                ))}
            </div>

            <MapWeatherPanel />
        </div>
    );
}

function QuestionRow({ item }: Readonly<{ item: (typeof QUESTIONS)[number] }>) {
    return (
        <li className="border-b border-border-strong py-3 last:border-b-0">
            <p className="m-0 text-[12.5px] leading-[1.4] font-bold text-foreground">
                {item.q}
            </p>
            {item.status === 'done' && (
                <p className="m-0 mt-1 font-serif text-[12px] leading-[1.5] text-foreground italic">
                    {item.a}
                </p>
            )}
            {item.status === 'pending' && (
                <div className="mt-1 flex items-center gap-1.5 text-[11px] leading-[1.2] text-foreground">
                    <Loader2 className="size-3 animate-spin" aria-hidden />
                    thinking about it.
                </div>
            )}
            {item.status === 'failed' && (
                <div className="mt-1.5 flex items-center gap-2">
                    <span className="text-[11px] leading-[1.2] text-destructive">
                        this one didn&apos;t come back.
                    </span>
                    <button
                        type="button"
                        className="rounded-full border border-border-strong bg-transparent px-2.25 py-1 font-sans text-[10px] leading-[1.2] font-bold text-foreground"
                    >
                        ask it again
                    </button>
                </div>
            )}
        </li>
    );
}

function AskAboutRun() {
    const [draft, setDraft] = useState('');
    return (
        <div className={cn('mb-4', NARRATION_CARD)}>
            <div className="mb-1 flex items-center gap-1.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-icon-accent uppercase">
                <MessageCircle className="size-3" aria-hidden />
                ask about this run
            </div>
            <p className="m-0 mt-2 font-serif text-sm leading-[1.3] text-foreground italic">
                &quot;the numbers are up there. ask me why.&quot;
            </p>
            <p className="m-0 mt-1.5 text-[11px] leading-[1.5] text-foreground">
                one run, one question at a time. temari can only read this run
                and your own history.
            </p>

            <ul className="m-0 mt-3 list-none p-0">
                {QUESTIONS.map((item) => (
                    <QuestionRow key={item.q} item={item} />
                ))}
            </ul>

            <div className="mt-3.5 mb-1 font-mono text-[8.5px] leading-[1.2] font-extrabold tracking-[.05em] text-foreground uppercase">
                starting points
            </div>
            <div className="mb-3.5 flex flex-wrap gap-1.5">
                {SUGGESTIONS.map((s) => (
                    <button
                        key={s}
                        type="button"
                        onClick={() => setDraft(s)}
                        className="rounded-full border border-border-strong bg-transparent px-2.5 py-1.5 text-left font-sans text-[10.5px] leading-[1.3] font-semibold text-foreground"
                    >
                        {s}
                    </button>
                ))}
            </div>

            <form
                onSubmit={(e) => e.preventDefault()}
                className="flex items-center gap-1.5"
            >
                <input
                    type="text"
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    maxLength={300}
                    placeholder="ask anything about this run"
                    aria-label="your question about this run"
                    className="min-w-0 flex-1 rounded-full border border-border-strong bg-muted px-3.5 py-2.5 font-sans text-[12px] text-foreground placeholder:text-foreground"
                />
                <button
                    type="submit"
                    disabled={draft.trim().length < 3}
                    className="flex flex-none items-center gap-1.5 rounded-full bg-btn-primary-bg px-3.5 py-2.5 font-sans text-[12px] font-bold text-btn-primary-fg disabled:opacity-50"
                >
                    <Send className="size-3.5" aria-hidden />
                    ask
                </button>
            </form>
        </div>
    );
}

const NARRATION_CARD =
    'rounded-[14px] border-[1.5px] border-[color-mix(in_oklab,var(--horizon-ink)_45%,var(--border-strong-fg))] bg-card p-4 shadow-[0_1px_2px_rgba(58,45,20,.06),0_1px_1px_rgba(58,45,20,.04),0_0_0_3px_color-mix(in_oklab,var(--horizon)_14%,transparent)]';

function RunLenses({
    rereadState,
}: Readonly<{ rereadState: 'ready' | 'cooldown' }>) {
    return (
        <div className="mb-4">
            <div className="mb-3 flex items-center gap-3">
                <FaceIcon
                    size={40}
                    ring="var(--horizon)"
                    fill="var(--card)"
                    feature="var(--foreground)"
                />
                <div className="min-w-0 flex-1">
                    <h2 className="m-0 font-serif text-base leading-[1.2] font-semibold text-foreground italic">
                        what temari says
                    </h2>
                    <p className="m-0 text-[11px] leading-[1.3] text-foreground">
                        the story of this run, and what stood out.
                    </p>
                </div>
            </div>

            <div className={NARRATION_CARD}>
                <div className="mb-1.5 flex items-center gap-1.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-icon-accent uppercase">
                    <MessageCircle className="size-3" aria-hidden />
                    this run&apos;s story
                </div>
                <p className="m-0 font-serif text-[12.5px] leading-[1.55] text-foreground italic">
                    this held together well — the middle third dipped under 4:35
                    without heart rate creeping past what a tempo effort should
                    cost you. the pace faded slightly in the last two
                    kilometers, which tracks with the accumulated distance
                    rather than anything going wrong.
                </p>

                <div className="my-3.5 h-px bg-border-strong" />

                <div className="mb-2 flex items-center gap-1.25 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-icon-accent uppercase">
                    <Lightbulb className="size-3" aria-hidden />
                    what stood out
                </div>
                <div className="flex flex-col gap-2.5">
                    {STOOD_OUT_CLAIMS.map((c) => (
                        <div key={c.text}>
                            <p className="m-0 font-serif text-[12px] leading-[1.4] text-foreground italic">
                                {c.text}
                            </p>
                            {(c.value || c.delta) && (
                                <div className="mt-1 flex gap-1.5">
                                    {c.value && (
                                        <span className="rounded-full bg-muted px-2 py-0.75 font-mono text-[9px] leading-[1.2] font-bold text-foreground">
                                            {c.value}
                                        </span>
                                    )}
                                    {c.delta && (
                                        <span className="rounded-full bg-horizon/20 px-2 py-0.75 font-mono text-[9px] leading-[1.2] font-bold text-icon-accent">
                                            {c.delta}
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
                <div className="mt-3 flex justify-end">
                    {rereadState === 'cooldown' ? (
                        <span className="inline-flex cursor-not-allowed items-center gap-1.25 rounded-full bg-muted px-2.75 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase">
                            <Clock className="size-3" aria-hidden />
                            next in 3h 05m
                        </span>
                    ) : (
                        <button
                            type="button"
                            className="inline-flex items-center gap-1.25 rounded-full border-none bg-muted px-2.75 py-1.5 font-mono text-[9px] leading-[1.2] font-extrabold tracking-[.04em] text-foreground uppercase"
                        >
                            <RefreshCw className="size-3" aria-hidden />
                            reread
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

const HR_SCALE_MIN = 100;
const HR_SCALE_MAX = 190;
const hrScalePct = (bpm: number) =>
    ((Math.min(Math.max(bpm, HR_SCALE_MIN), HR_SCALE_MAX) - HR_SCALE_MIN) /
        (HR_SCALE_MAX - HR_SCALE_MIN)) *
    100;

function VitalsCard() {
    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-3.5 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                vitals
            </div>

            <div className="mb-1.5 font-mono text-[9px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                heart rate
            </div>
            <div className="flex items-end justify-between">
                <div>
                    <b className="font-mono text-[26px] leading-[1] font-extrabold text-foreground">
                        152
                    </b>
                    <span className="ml-1 font-mono text-[9px] leading-[1] tracking-[.03em] text-foreground uppercase">
                        avg bpm
                    </span>
                </div>
                <div>
                    <b className="font-mono text-base leading-[1] font-bold text-foreground">
                        171
                    </b>
                    <span className="ml-1 font-mono text-[8.5px] leading-[1] tracking-[.03em] text-foreground uppercase">
                        max
                    </span>
                </div>
            </div>
            <div className="relative mt-2.5 h-2 rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-icon-accent"
                    style={{ width: `${hrScalePct(152)}%` }}
                />
                <div
                    className="absolute top-1/2 h-3.5 w-[2px] -translate-y-1/2 rounded-full bg-text-2"
                    style={{ left: `${hrScalePct(171)}%` }}
                />
            </div>

            <div className="mt-4 grid grid-cols-3 gap-2">
                <div className="rounded-[10px] bg-muted p-2.5 text-center">
                    <Footprints
                        className="mx-auto size-4 text-icon-accent"
                        aria-hidden
                    />
                    <b className="mt-1.5 block font-mono text-sm leading-[1.2] font-extrabold text-foreground">
                        176
                    </b>
                    <span className="block text-[9px] leading-[1.2] text-foreground">
                        spm avg
                    </span>
                </div>
                <div className="rounded-[10px] bg-muted p-2.5 text-center">
                    <Mountain
                        className="mx-auto size-4 text-icon-accent"
                        aria-hidden
                    />
                    <b className="mt-1.5 block font-mono text-sm leading-[1.2] font-extrabold text-foreground">
                        4%
                    </b>
                    <span className="block text-[9px] leading-[1.2] text-foreground">
                        steepest grade
                    </span>
                </div>
                <div className="rounded-[10px] bg-muted p-2.5 text-center">
                    <Scale
                        className="mx-auto size-4 text-icon-accent"
                        aria-hidden
                    />
                    <b className="mt-1.5 block font-mono text-sm leading-[1.2] font-extrabold text-foreground">
                        4:31
                    </b>
                    <span className="block text-[9px] leading-[1.2] text-foreground">
                        flat pace /km
                    </span>
                </div>
            </div>

            <div className="mt-3.5">
                <div className="flex items-center justify-between">
                    <span className="font-mono text-[9px] leading-[1.2] tracking-[.04em] text-foreground uppercase">
                        decoupling
                    </span>
                    <b className="font-mono text-[13px] leading-[1.2] font-extrabold text-icon-accent">
                        +3.2%
                    </b>
                </div>
                <div className="relative mt-1.75 h-1.5 rounded-full bg-[linear-gradient(90deg,var(--leaf),var(--citrus),var(--ember))]">
                    <div
                        className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-card bg-foreground shadow-e1"
                        style={{ left: '27%' }}
                    />
                </div>
                <p className="m-0 mt-1.75 text-[10.5px] leading-[1.3] text-foreground">
                    breathing held steady to the end
                </p>
            </div>
        </div>
    );
}

type SplitTip = {
    key: string | number;
    x: number;
    pace: string;
    hr: number;
    cadence: number;
};

function SplitsChartCard() {
    const paces = SPLITS.map((s) => paceToSec(s.pace));
    const min = Math.min(...paces);
    const max = Math.max(...paces);
    const barHeight = (sec: number) =>
        32 + ((max - sec) / (max - min || 1)) * 84;
    const fastest = SPLITS.find((s) => s.fastest) ?? SPLITS[0];

    const hrColumns = [
        ...SPLITS,
        { km: SPLIT_PARTIAL.km, hr: SPLIT_PARTIAL.hr },
    ];
    const hrPoints = hrColumns
        .map(
            (s, i) =>
                `${((i + 0.5) / hrColumns.length) * 100},${splitHrY(s.hr)}`,
        )
        .join(' ');

    const chartRef = useRef<HTMLDivElement>(null);
    const tipRef = useRef<HTMLDivElement>(null);
    const [tip, setTip] = useState<SplitTip | null>(null);

    useLayoutEffect(() => {
        if (!tip || !tipRef.current || !chartRef.current) return;
        const chartWidth = chartRef.current.clientWidth;
        const halfTipWidth = tipRef.current.offsetWidth / 2;
        const clampedX = Math.min(
            Math.max(tip.x, halfTipWidth + 4),
            chartWidth - halfTipWidth - 4,
        );
        tipRef.current.style.left = `${clampedX}px`;
    }, [tip]);

    function handleBarClick(
        e: React.MouseEvent<HTMLButtonElement>,
        next: Omit<SplitTip, 'x'>,
    ) {
        if (!chartRef.current) return;
        const chartRect = chartRef.current.getBoundingClientRect();
        const barRect = e.currentTarget.getBoundingClientRect();
        setTip((prev) =>
            prev?.key === next.key
                ? null
                : {
                      ...next,
                      x: barRect.left + barRect.width / 2 - chartRect.left,
                  },
        );
    }

    return (
        <div className="mb-4 rounded-[14px] border border-border-strong bg-card p-4 shadow-e1">
            <div className="mb-0.5 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                splits per km
            </div>
            <p className="m-0 mb-1.5 text-[9.5px] leading-[1.3] text-foreground">
                taller bar, faster km · dashed line tracks heart rate — tap a
                bar for its pace.
            </p>
            <div className="mb-2.5 flex items-center gap-3">
                <span className="flex items-center gap-1 font-mono text-[8px] leading-[1.2] tracking-[.03em] text-foreground uppercase">
                    <i className="h-[3px] w-3 rounded-full bg-horizon" />
                    pace
                </span>
                <span className="flex items-center gap-1 font-mono text-[8px] leading-[1.2] tracking-[.03em] text-foreground uppercase">
                    <i className="h-[3px] w-3 rounded-full bg-[repeating-linear-gradient(90deg,var(--foreground)_0_3px,transparent_3px_5px)]" />
                    heart rate
                </span>
            </div>

            <div ref={chartRef} className="relative">
                {tip && (
                    <div
                        ref={tipRef}
                        className="absolute z-10 -translate-x-1/2 -translate-y-full rounded-[10px] bg-ink px-2.75 py-1.75 text-center whitespace-nowrap shadow-e2"
                        style={{ top: -8, left: tip.x }}
                    >
                        <div className="font-mono text-[11.5px] leading-[1.2] font-extrabold text-cream">
                            {tip.pace}/km
                        </div>
                        <div className="mt-0.5 font-mono text-[8.5px] leading-[1.2] text-cream">
                            ♡ {tip.hr} · {tip.cadence} spm
                        </div>
                        <span className="absolute top-full left-1/2 -translate-x-1/2 border-[5px] border-transparent border-t-ink" />
                    </div>
                )}
                <svg
                    viewBox="0 0 100 116"
                    preserveAspectRatio="none"
                    className="pointer-events-none absolute inset-0 h-[116px] w-full"
                >
                    <polyline
                        points={hrPoints}
                        fill="none"
                        stroke="var(--foreground)"
                        strokeWidth="1.5"
                        strokeDasharray="3 2.5"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        opacity=".55"
                        vectorEffect="non-scaling-stroke"
                    />
                </svg>
                <div className="flex h-[116px] items-end gap-[5px]">
                    {SPLITS.map((s) => (
                        <button
                            key={s.km}
                            type="button"
                            onClick={(e) =>
                                handleBarClick(e, {
                                    key: s.km,
                                    pace: s.pace,
                                    hr: s.hr,
                                    cadence: s.cadence,
                                })
                            }
                            className="flex h-full flex-1 flex-col items-center justify-end gap-1 border-none bg-transparent p-0"
                        >
                            {s.fastest && (
                                <Star
                                    className="size-3 flex-none fill-current text-icon-accent"
                                    aria-hidden
                                />
                            )}
                            <div
                                className={cn(
                                    'w-full rounded-t-[4px] transition-opacity',
                                    s.fastest ? 'bg-horizon' : 'bg-sky-2',
                                    tip && tip.key !== s.km && 'opacity-40',
                                )}
                                style={{ height: barHeight(paceToSec(s.pace)) }}
                            />
                            <span className="font-mono text-[7px] leading-[1.2] text-foreground">
                                {s.km}
                            </span>
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={(e) =>
                            handleBarClick(e, {
                                key: SPLIT_PARTIAL.km,
                                pace: SPLIT_PARTIAL.pace,
                                hr: SPLIT_PARTIAL.hr,
                                cadence: SPLIT_PARTIAL.cadence,
                            })
                        }
                        className="flex h-full flex-1 flex-col items-center justify-end gap-1 border-none bg-transparent p-0"
                    >
                        <div
                            className={cn(
                                'w-full rounded-t-[4px] border border-dashed border-border-strong transition-opacity',
                                tip &&
                                    tip.key !== SPLIT_PARTIAL.km &&
                                    'opacity-40',
                            )}
                            style={{
                                height: barHeight(
                                    paceToSec(SPLIT_PARTIAL.pace),
                                ),
                            }}
                        />
                        <span className="font-mono text-[7px] leading-[1.2] text-foreground">
                            {SPLIT_PARTIAL.km}
                        </span>
                    </button>
                </div>
            </div>

            <div className="mt-4 flex items-center justify-between rounded-[10px] bg-horizon/14 px-3 py-2.5">
                <div className="flex items-center gap-2">
                    <Star
                        className="size-3.5 flex-none fill-current text-icon-accent"
                        aria-hidden
                    />
                    <span className="text-[11px] leading-[1.2] font-bold text-foreground">
                        km{fastest.km} · fastest · {fastest.hr} bpm
                    </span>
                </div>
                <span className="font-mono text-[12px] leading-[1.2] font-extrabold text-icon-accent">
                    {fastest.pace}/km
                </span>
            </div>
        </div>
    );
}

function LapsCarousel() {
    return (
        <div className="mb-4">
            <div className="mb-2 px-0.5 font-mono text-[9.5px] leading-[1.2] font-extrabold tracking-[.06em] text-foreground uppercase">
                laps
            </div>
            <div className="-mx-4 flex gap-2.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {LAPS.map((l) => (
                    <div
                        key={l.lap}
                        className={cn(
                            'flex w-[128px] flex-none flex-col gap-2 rounded-[14px] border p-3.5 shadow-e1',
                            l.fastest
                                ? 'border-icon-accent bg-horizon/10'
                                : 'border-border-strong bg-card',
                        )}
                    >
                        <div className="flex items-center justify-between">
                            <span className="font-mono text-[9px] leading-[1.2] font-extrabold text-foreground uppercase">
                                lap {l.lap}
                            </span>
                            {l.fastest && (
                                <Zap
                                    className="size-3 flex-none fill-current text-icon-accent"
                                    aria-hidden
                                />
                            )}
                        </div>
                        <b className="font-mono text-xl leading-[1.1] font-extrabold text-foreground">
                            {l.pace}
                        </b>
                        <span className="text-[9.5px] leading-[1.2] text-foreground">
                            {l.dist} · {l.time}
                        </span>
                        <div className="mt-1 flex items-center gap-2.5 font-mono text-[10px] leading-[1.2] text-foreground">
                            <span>♡ {l.hr}</span>
                            <span className="flex items-center gap-0.5">
                                <Footprints className="size-2.5" aria-hidden />
                                {l.cadence}
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export function ActivityDetailScreen({
    awaitingDetail,
    pastYouState,
    rereadState,
}: Readonly<{
    awaitingDetail: 'ready' | 'hydrating';
    pastYouState: 'match' | 'none';
    rereadState: 'ready' | 'cooldown';
}>) {
    return (
        <div className="px-4 pt-16 pb-7 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-6 @min-[900px]:pb-14">
            <div className="mb-3 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.12em] text-foreground uppercase">
                activity
            </div>

            {awaitingDetail === 'hydrating' && (
                <HydratingNotice stopped={false} />
            )}

            <HeroPanel />

            {awaitingDetail === 'ready' && pastYouState === 'match' && (
                <PastYouCard />
            )}

            {awaitingDetail === 'ready' && (
                <>
                    <RunLenses rereadState={rereadState} />
                    <AskAboutRun />

                    <div className="mt-1 mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.09em] text-foreground uppercase">
                        the breakdown
                    </div>
                    <VitalsCard />
                    <SplitsChartCard />
                    <LapsCarousel />
                </>
            )}

            <p className="m-0 mt-2 text-center text-[9.5px] leading-[1.2] text-foreground">
                synced from strava · 19 feb 2026 · 07:15 · #4821
            </p>
        </div>
    );
}
