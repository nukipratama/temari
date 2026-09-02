import { Head, useForm, usePage } from '@inertiajs/react';
import { lazy, Suspense, useId, useState } from 'react';

import type { SharedProps } from '@/types/inertia';

import BrandMark from '@/components/BrandMark';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import { bareLayout } from '@/layouts/BareShell';
import { cn } from '@/lib/cn';

// Lazy: KartuMini's rarity-chrome glyphs statically import framer-motion,
// which this route's entry-chunk budget must stay clear of.
const KartuMini = lazy(() => import('@/components/card/KartuMini'));

interface CopyBlock {
    headline: string;
    points: string[];
}

interface LoginProps {
    authStravaUrl: string;
    /** Deep link to return to after login (sanitized same-host path), or null. */
    from?: string | null;
    dataUse?: CopyBlock;
    trainingDisclaimer?: { headline: string; text: string };
}

interface WhyItem {
    icon: string;
    label: string;
    desc: string;
}

const WHY_FAIR: ReadonlyArray<WhyItem> = [
    {
        icon: 'mdi:magnify-scan',
        label: 'fair matches only',
        desc: 'same pace band, comparable distance, recent history',
    },
    {
        icon: 'mdi:swap-vertical-bold',
        label: 'reads the gap, not the vibe',
        desc: 'pace and heart rate, together',
    },
    {
        icon: 'mdi:help-rhombus-outline',
        label: 'says when it cannot tell',
        desc: 'no trend gets invented to fill the space',
    },
];

const WHY_GET: ReadonlyArray<WhyItem> = [
    {
        icon: 'mdi:calendar-check-outline',
        label: 'a plan that answers to your week',
        desc: 'built from the volume you actually ran',
    },
    {
        icon: 'mdi:trophy-outline',
        label: 'records and recaps',
        desc: 'your PRs, your weeks, your months',
    },
];

// Plain anchors, not Inertia <Link>: these are the pages a stranger reads
// before deciding to connect a Strava account, so they must survive the SPA
// runtime failing to boot at all.
const LEGAL_LINKS: ReadonlyArray<{ href: string; label: string }> = [
    { href: '/terms', label: 'terms' },
    { href: '/privacy', label: 'privacy' },
    { href: '/ai-use', label: 'how temari uses AI' },
    { href: '/training-disclaimer', label: 'training disclaimer' },
];

const HERO_GRADIENT =
    'linear-gradient(180deg, var(--color-sky-deep) 0%, var(--color-sky) 40%, oklch(42% 0.06 126) 100%)';

const HERO_GLOW =
    'radial-gradient(circle, color-mix(in oklab, var(--color-horizon) 30%, transparent) 0%, color-mix(in oklab, var(--color-horizon) 15%, transparent) 30%, transparent 60%)';

export default function Login({
    authStravaUrl,
    from = null,
    dataUse,
    trainingDisclaimer,
}: Readonly<LoginProps>) {
    const { demoLoginEnabled } = usePage<SharedProps>().props;
    const demoForm = useForm({ from });
    const submitDemo = () => demoForm.post('/auth/demo');

    const stravaUrl = from
        ? `${authStravaUrl}?from=${encodeURIComponent(from)}`
        : authStravaUrl;

    return (
        <>
            <Head title="Temari · You vs Past You" />

            <Hero />

            <ConnectPanel
                authStravaUrl={stravaUrl}
                demoLoginEnabled={demoLoginEnabled}
                onSubmitDemo={submitDemo}
                demoPending={demoForm.processing}
            />

            <main className="px-4.5 pt-6.5 pb-2 min-[900px]:mx-auto min-[900px]:max-w-column min-[1280px]:max-w-column-wide min-[900px]:px-6 min-[900px]:pt-11">
                <Eyebrow token="micro" className="mb-2.5 text-foreground">
                    why the comparison is fair
                </Eyebrow>
                <WhyList
                    items={WHY_FAIR}
                    wideClassName="min-[900px]:grid min-[900px]:grid-cols-3"
                />

                <Eyebrow token="micro" className="mb-2.5 text-foreground">
                    what you get
                </Eyebrow>
                <KartuTeaser />
                <WhyList
                    items={WHY_GET}
                    wideClassName="min-[900px]:mx-auto min-[900px]:inline-grid min-[900px]:grid-cols-2"
                />

                <DataUseDisclosure
                    dataUse={dataUse}
                    trainingDisclaimer={trainingDisclaimer}
                />
            </main>

            <footer className="mt-1 border-t border-border px-4.5 pt-1 pb-7 text-center min-[900px]:mx-auto min-[900px]:max-w-column min-[1280px]:max-w-column-wide">
                <nav
                    aria-label="Legal"
                    className="mt-4 mb-2 flex flex-wrap justify-center gap-x-3 gap-y-1"
                >
                    {LEGAL_LINKS.map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            className="focus-ring inline-flex min-h-6 items-center rounded text-xs text-text-2 underline underline-offset-2 hover:text-foreground"
                        >
                            {link.label}
                        </a>
                    ))}
                </nav>
                <p className="text-label-micro text-text-3">
                    temari · your running companion, every step
                </p>
            </footer>
        </>
    );
}

function Hero() {
    return (
        <header
            className="relative overflow-hidden px-5.5 pt-14 pb-6 text-cream min-[900px]:px-14 min-[900px]:pt-16 min-[900px]:pb-9"
            style={{ background: HERO_GRADIENT }}
        >
            <span
                aria-hidden
                className="pointer-events-none absolute top-[10%] left-1/2 size-85 -translate-x-1/2 -translate-y-1/2 rounded-full blur-[2px]"
                style={{ background: HERO_GLOW }}
            />

            <div className="relative">
                <BrandMark tone="cream" />

                <Eyebrow token="hero" tone="horizon" className="mt-4.5">
                    running companion
                </Eyebrow>
                <h1 className="mt-2 font-serif text-display-sm font-semibold text-cream italic">
                    you vs
                    <br />
                    <em className="text-horizon italic">past you.</em>
                </h1>

                <p className="mt-2.5 max-w-[32ch] text-sm leading-relaxed text-cream min-[900px]:max-w-[44ch] min-[900px]:text-base">
                    every run gets matched against one you have already done.
                    temari reads the gap and tells you which way it is going.
                </p>
            </div>
        </header>
    );
}

interface ConnectPanelProps {
    authStravaUrl: string;
    demoLoginEnabled: boolean;
    onSubmitDemo: () => void;
    demoPending: boolean;
}

/**
 * The one place the Strava brand mark appears. Neutral ground on purpose: the
 * surrounding gold and sky accents stay off the panel so the mark keeps the
 * breathing room Strava's brand guidelines require.
 */
function ConnectPanel({
    authStravaUrl,
    demoLoginEnabled,
    onSubmitDemo,
    demoPending,
}: Readonly<ConnectPanelProps>) {
    return (
        <Card className="relative z-5 mx-3.5 -mt-4.5 gap-0 bg-muted px-4.5 pt-5 pb-4.5 text-foreground shadow-e3 ring-0 min-[900px]:mx-auto min-[900px]:-mt-7.5 min-[900px]:max-w-[440px]">
            <div className="text-label-micro text-foreground">
                start with your history
            </div>
            <p className="mt-1.5 text-xs leading-relaxed text-text-2">
                sign in through Strava, no separate account, read-only access.
            </p>

            <a
                href={authStravaUrl}
                className="focus-ring mt-3.5 flex w-full items-center justify-center gap-2 rounded-full bg-strava-orange py-3.5 text-sm font-bold text-white transition hover:bg-strava-orange-hover"
            >
                <svg
                    viewBox="0 0 24 24"
                    className="size-4"
                    fill="currentColor"
                    aria-hidden
                >
                    <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                </svg>
                connect with Strava
            </a>

            {demoLoginEnabled && (
                <Button
                    type="button"
                    variant="ghost"
                    onClick={onSubmitDemo}
                    disabled={demoPending}
                    className="mt-2 h-auto w-full gap-1.5 px-0 py-2.5 text-sm font-semibold text-foreground"
                >
                    <Icon
                        icon="mdi:play-circle-outline"
                        width={14}
                        height={14}
                        aria-hidden
                    />
                    try the demo
                </Button>
            )}

            <p className="mt-3 text-center text-xs leading-relaxed text-text-2">
                read-only, and only for you.{' '}
                <a
                    href="/privacy"
                    className="focus-ring rounded text-foreground underline underline-offset-2 hover:text-text-2"
                >
                    what temari stores
                </a>
            </p>
        </Card>
    );
}

function WhyList({
    items,
    wideClassName,
}: Readonly<{ items: ReadonlyArray<WhyItem>; wideClassName: string }>) {
    return (
        <ul
            className={cn(
                'mb-6 flex flex-col divide-y divide-border overflow-hidden rounded-2xl border border-border shadow-e1',
                'min-[900px]:gap-2.5 min-[900px]:divide-y-0 min-[900px]:border-none min-[900px]:shadow-none',
                wideClassName,
            )}
        >
            {items.map((item) => (
                <WhyRow key={item.label} {...item} />
            ))}
        </ul>
    );
}

function WhyRow({ icon, label, desc }: Readonly<WhyItem>) {
    return (
        <li className="flex items-center gap-2.5 bg-card px-3.5 py-3 min-[900px]:flex-col min-[900px]:items-start min-[900px]:gap-2.5 min-[900px]:rounded-2xl min-[900px]:border min-[900px]:border-border-strong min-[900px]:p-4 min-[900px]:shadow-e1">
            <span
                aria-hidden
                className="flex size-7.5 flex-none items-center justify-center rounded-lg bg-horizon/[0.18] text-icon-accent"
            >
                <Icon icon={icon} width={16} height={16} aria-hidden />
            </span>
            <span className="flex flex-col">
                <b className="text-xs leading-tight font-bold text-foreground">
                    {label}
                </b>
                <span className="text-xs leading-tight text-text-2">
                    {desc}
                </span>
            </span>
        </li>
    );
}

function KartuTeaser() {
    return (
        <Card className="mb-6 flex-row items-center gap-3.5 rounded-2xl border border-border p-3.5 shadow-e1 ring-0">
            <Suspense
                fallback={
                    <div
                        aria-hidden
                        className="h-[84px] w-[78px] flex-none rounded-sm bg-cream-deep"
                    />
                }
            >
                <KartuMini
                    compact
                    name="10K Sunrise"
                    rarity="legendary"
                    mood="blazing"
                    polyline="~s{d@ofekSoRaMcPdMg@b^zFtV?bN{FtVf@b^bPdMnRaMlIqTdHqFfQcAfQcP?g[gQcPgQcAeHqFmIqT"
                    className="shadow-e1"
                />
            </Suspense>
            <p className="text-xs leading-relaxed text-text-2">
                <b className="text-foreground">a card for every run</b>, route,
                pace and mood, collectible and occasionally rare. this is a real
                card, not a mockup.
            </p>
        </Card>
    );
}

/**
 * Native disclosure, deliberately not `ui/collapsible`: that primitive is Base
 * UI backed, and this route's entry-chunk budget (scripts/check-entry-chunks.mjs)
 * has no room for the portal machinery it drags in.
 */
function DataUseDisclosure({
    dataUse,
    trainingDisclaimer,
}: Readonly<Pick<LoginProps, 'dataUse' | 'trainingDisclaimer'>>) {
    const [open, setOpen] = useState(false);
    const panelId = useId();

    if (!dataUse && !trainingDisclaimer) {
        return null;
    }

    return (
        <section className="mb-5.5 overflow-hidden rounded-2xl border border-border bg-card shadow-e1">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((wasOpen) => !wasOpen)}
                className="focus-ring group flex w-full items-center justify-between px-3.5 py-3.5 text-left text-xs leading-tight font-bold text-foreground"
            >
                data &amp; AI use
                <Icon
                    icon="mdi:chevron-down"
                    width={18}
                    height={18}
                    aria-hidden
                    className="text-foreground transition-transform group-aria-expanded:rotate-180"
                />
            </button>

            <div
                id={panelId}
                hidden={!open}
                className="flex flex-col gap-2 px-3.5 pb-3.5 text-xs leading-relaxed text-text-2"
            >
                {dataUse && (
                    <div>
                        <b className="block leading-tight text-foreground">
                            what temari stores
                        </b>
                        {dataUse.points.map((point) => (
                            <p key={point} className="mt-1">
                                {point}
                            </p>
                        ))}
                    </div>
                )}

                {trainingDisclaimer && (
                    <div>
                        <b className="block leading-tight text-foreground">
                            before you take its advice
                        </b>
                        <p className="mt-1">{trainingDisclaimer.text}</p>
                        <a
                            href="/training-disclaimer"
                            className="focus-ring mt-1.5 inline-flex min-h-6 items-center gap-1 rounded text-foreground underline underline-offset-2 hover:text-text-2"
                        >
                            read the whole disclaimer
                            <Icon
                                icon="mdi:arrow-right"
                                width={12}
                                height={12}
                                aria-hidden
                            />
                        </a>
                    </div>
                )}
            </div>
        </section>
    );
}

Login.layout = bareLayout;
