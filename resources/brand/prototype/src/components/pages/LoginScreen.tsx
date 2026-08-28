import {
    CalendarCheck,
    ChevronDown,
    HelpCircle,
    PlayCircle,
    ScanSearch,
    Trophy,
    ArrowUpDown,
} from 'lucide-react';

import { TemariMark } from '@/components/rack/TemariMark';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

const WHY_FAIR = [
    {
        icon: ScanSearch,
        title: 'fair matches only',
        body: 'same pace band, comparable distance, recent history',
    },
    {
        icon: ArrowUpDown,
        title: 'reads the gap, not the vibe',
        body: 'pace and heart rate, together',
    },
    {
        icon: HelpCircle,
        title: "says when it can't tell",
        body: 'no trend gets invented to fill the space',
    },
];

const WHY_GET = [
    {
        icon: CalendarCheck,
        title: 'a plan that answers to your week',
        body: 'built from the volume you actually ran',
    },
    {
        icon: Trophy,
        title: 'records and recaps',
        body: 'your PRs, your weeks, your months',
    },
];

function WhyRow({
    icon: Icon,
    title,
    body,
}: Readonly<{ icon: typeof ScanSearch; title: string; body: string }>) {
    return (
        <div className="flex items-center gap-2.5 bg-card px-3.5 py-3 @min-[900px]:flex-col @min-[900px]:items-start @min-[900px]:gap-2.5 @min-[900px]:rounded-2xl @min-[900px]:border @min-[900px]:border-border-strong @min-[900px]:p-4 @min-[900px]:shadow-[0_1px_2px_rgba(58,45,20,.06),0_1px_1px_rgba(58,45,20,.04)]">
            <span className="flex size-7.5 flex-none items-center justify-center rounded-lg bg-horizon/22 text-icon-accent">
                <Icon className="size-4" aria-hidden />
            </span>
            <span className="flex flex-col">
                <b className="leading-[1.2] text-[12.5px] font-bold text-foreground">
                    {title}
                </b>
                <span className="leading-[1.2] text-[11.5px] text-foreground">
                    {body}
                </span>
            </span>
        </div>
    );
}

export function LoginScreen({
    onConnect,
    onTryDemo,
}: Readonly<{ onConnect: () => void; onTryDemo: () => void }>) {
    return (
        <div>
            {/* Hero — deliberately NOT theme-reactive, same immersive dark
                gradient in both modes, matching the shipped page. */}
            <div className="relative overflow-hidden bg-gradient-to-b from-sky-deep from-0% via-sky via-40% to-[oklch(42%_0.06_126)] px-[22px] pt-14 pb-6 text-cream @min-[900px]:px-14 @min-[900px]:pt-16 @min-[900px]:pb-9">
                <div
                    className="pointer-events-none absolute top-[10%] left-1/2 size-[340px] -translate-x-1/2 -translate-y-1/2 rounded-full blur-[2px]"
                    style={{
                        background:
                            'radial-gradient(circle, color-mix(in oklab, var(--horizon) 30%, transparent) 0%, color-mix(in oklab, var(--horizon) 15%, transparent) 30%, transparent 60%)',
                    }}
                />
                <div className="relative flex items-center gap-2 text-[15px] leading-[1.2] font-extrabold tracking-tight">
                    <TemariMark size={26} />
                    temari
                </div>
                <div className="relative mt-4.5 font-mono text-[10px] leading-[1.2] font-bold tracking-[.16em] text-horizon uppercase">
                    running companion
                </div>
                <h1 className="relative mt-2 font-serif text-[34px] leading-[1.02] font-semibold text-cream italic @min-[900px]:text-[46px]">
                    you vs
                    <br />
                    <em className="text-horizon italic">past you.</em>
                </h1>
                <p className="relative mt-2.5 max-w-[32ch] text-[13.5px] leading-[1.5] text-cream @min-[900px]:max-w-[44ch] @min-[900px]:text-[15px]">
                    every run gets matched against one you've already done.
                    temari reads the gap and tells you which way it's going.
                </p>
            </div>

            {/* Connect panel */}
            <Card className="relative z-5 mx-3.5 -mt-4.5 gap-0 rounded-[26px] bg-muted pt-5 pr-4.5 pb-4.5 pl-4.5 shadow-lg ring-0 @min-[900px]:mx-auto @min-[900px]:-mt-7.5 @min-[900px]:max-w-[440px]">
                <div className="font-mono text-[11px] leading-[1.2] font-extrabold tracking-[.08em] text-foreground uppercase">
                    start with your history
                </div>
                <p className="mt-1.5 text-[12.5px] leading-[1.5] text-foreground">
                    sign in through Strava — no separate account, read-only
                    access.
                </p>
                <Button
                    onClick={onConnect}
                    className="mt-3.5 h-auto w-full gap-2 rounded-full bg-[#fc4c02] px-0 py-3.5 text-sm font-bold text-white hover:bg-[#fc4c02]/90"
                >
                    <svg
                        viewBox="0 0 24 24"
                        width="16"
                        height="16"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                    </svg>
                    connect with Strava
                </Button>
                <Button
                    onClick={onTryDemo}
                    variant="ghost"
                    className="mt-2 h-auto w-full gap-1.5 px-0 py-2.5 text-[13px] font-semibold text-foreground"
                >
                    <PlayCircle className="size-3.5" aria-hidden />
                    try the demo
                </Button>
                <p className="mt-3 text-center text-[11px] leading-[1.5] text-foreground">
                    read-only, and only for you.{' '}
                    <a
                        href="#"
                        className="text-foreground underline underline-offset-2"
                    >
                        what temari stores
                    </a>
                </p>
            </Card>

            {/* Below-the-fold pitch */}
            <div className="px-4.5 pt-6.5 pb-2 @min-[900px]:mx-auto @min-[900px]:max-w-[760px] @min-[900px]:px-6 @min-[900px]:pt-11">
                <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.1em] text-foreground uppercase">
                    why the comparison is fair
                </div>
                <div className="mb-6 flex flex-col gap-px overflow-hidden rounded-2xl border border-border bg-border shadow-sm @min-[900px]:grid @min-[900px]:grid-cols-3 @min-[900px]:gap-2.5 @min-[900px]:border-none @min-[900px]:bg-transparent @min-[900px]:shadow-none">
                    {WHY_FAIR.map((w) => (
                        <WhyRow key={w.title} {...w} />
                    ))}
                </div>

                <div className="mb-2.5 font-mono text-[10px] leading-[1.2] font-extrabold tracking-[.1em] text-foreground uppercase">
                    what you get
                </div>
                <Card className="mb-6 flex-row items-center gap-3.5 rounded-2xl border border-border p-3.5 shadow-sm ring-0">
                    <div className="relative h-21 w-19.5 flex-none overflow-hidden rounded-lg bg-gradient-to-br from-sky to-sky-2 shadow-[0_0_0_1px_color-mix(in_oklab,var(--citrus)_55%,transparent),inset_0_0_12px_color-mix(in_oklab,var(--citrus)_55%,transparent)]">
                        <svg
                            viewBox="0 0 78 84"
                            fill="none"
                            className="absolute inset-0"
                        >
                            <path
                                d="M4,60 Q25,40 40,50 T74,20"
                                stroke="white"
                                strokeWidth="1.4"
                                strokeOpacity=".35"
                                strokeLinecap="round"
                            />
                        </svg>
                        <span className="absolute bottom-1 left-1 rounded bg-[rgba(11,16,23,.65)] px-1.5 py-0.5 font-mono text-[7px] leading-[1.2] font-extrabold tracking-[.04em] text-citrus uppercase">
                            Legendary
                        </span>
                    </div>
                    <p className="m-0 text-xs leading-[1.5] text-foreground">
                        <b className="text-foreground">a card for every run</b>{' '}
                        — route, pace and mood, collectible and occasionally
                        rare. this is a real card, not a mockup.
                    </p>
                </Card>

                <div className="mb-6 flex flex-col gap-px overflow-hidden rounded-2xl border border-border bg-border shadow-sm @min-[900px]:inline-grid @min-[900px]:grid-cols-2 @min-[900px]:mx-auto @min-[900px]:gap-2.5 @min-[900px]:border-none @min-[900px]:bg-transparent @min-[900px]:shadow-none">
                    {WHY_GET.map((w) => (
                        <WhyRow key={w.title} {...w} />
                    ))}
                </div>

                <Collapsible className="mb-5.5 overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <CollapsibleTrigger
                        className={cn(
                            'group flex w-full items-center justify-between px-3.5 py-3.5 text-left text-[12.5px] leading-[1.2] font-bold text-foreground',
                        )}
                    >
                        data &amp; AI use
                        <ChevronDown
                            className="size-4.5 text-foreground transition-transform group-aria-expanded:rotate-180"
                            aria-hidden
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="flex flex-col gap-2 px-3.5 pb-3.5 text-[11.5px] leading-[1.55] text-foreground">
                        <div>
                            <b className="block text-[11.5px] leading-[1.2] text-foreground">
                                what temari stores
                            </b>
                            only what Strava already has: your runs, routes and
                            pace. nothing is sold or shared with another
                            account.
                        </div>
                        <div>
                            <b className="block text-[11.5px] leading-[1.2] text-foreground">
                                before you take its advice
                            </b>
                            verdicts are pace/HR comparisons, not coaching. read
                            the whole training disclaimer before changing a
                            training plan around one.
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            </div>

            <div className="mt-1 border-t border-border px-4.5 pt-1 pb-7 text-center @min-[900px]:mx-auto @min-[900px]:max-w-[760px]">
                <nav className="mt-4 mb-2 flex flex-wrap justify-center gap-x-3 gap-y-1">
                    {[
                        'terms',
                        'privacy',
                        'how temari uses AI',
                        'training disclaimer',
                    ].map((l) => (
                        <a
                            key={l}
                            href="#"
                            className="text-[11px] leading-[1.2] text-foreground underline underline-offset-2"
                        >
                            {l}
                        </a>
                    ))}
                </nav>
                <p className="m-0 font-mono text-[9.5px] leading-[1.2] tracking-[.06em] text-foreground uppercase">
                    temari · your running companion, every step
                </p>
            </div>
        </div>
    );
}
