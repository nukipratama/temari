import { Head, useForm, usePage } from '@inertiajs/react';
import { lazy, Suspense } from 'react';

import type { SharedProps } from '@/types/inertia';

import BrandMark from '@/components/BrandMark';
import TemariProto from '@/components/temari/TemariProto';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import LegacyCard from '@/components/ui/LegacyCard';
import PageHero from '@/components/ui/PageHero';
import SectionLabel from '@/components/ui/SectionLabel';
import { bareLayout } from '@/layouts/BareShell';

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

const MATCHING: ReadonlyArray<{
    icon: string;
    label: string;
    desc: string;
}> = [
    {
        icon: 'mdi:magnify-scan',
        label: 'it finds a fair match',
        desc: 'same pace band, within 500 m of the distance, three weeks to a year back. a run that is not comparable does not get used against you.',
    },
    {
        icon: 'mdi:swap-vertical-bold',
        label: 'it reads the gap, not the vibe',
        desc: 'pace calls it once the gap clears the noise. when pace comes back flat, heart rate calls it, so the same pace at a higher HR counts as a loss.',
    },
    {
        icon: 'mdi:help-rhombus-outline',
        label: 'it says when it cannot tell',
        desc: 'under two fair pairings and the answer is "not enough history yet", plus how close you are to one. no trend gets invented to fill the space.',
    },
];

const GETS: ReadonlyArray<{ icon: string; label: string; desc: string }> = [
    {
        icon: 'mdi:cards-outline',
        label: 'a card for every run',
        desc: 'your route, your pace, the mood of the day. collectible, and occasionally rare.',
    },
    {
        icon: 'mdi:calendar-check-outline',
        label: 'a plan that answers to your week',
        desc: 'built from the volume you actually ran, not the volume you meant to run.',
    },
    {
        icon: 'mdi:trophy-outline',
        label: 'records and recaps',
        desc: 'your PRs, your weeks, your months. each one comes with the number attached.',
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
    'linear-gradient(180deg, var(--color-sky-deep) 0%, var(--color-sky) 38%, var(--color-sky-2) 62%, oklch(58% 0.10 38) 82%, var(--color-horizon-deep) 100%)';

const SUN_GLOW =
    'radial-gradient(circle, oklch(80% 0.14 55 / 0.6) 0%, oklch(72% 0.13 50 / 0.25) 28%, transparent 58%)';

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

    const connect = (
        <ConnectPanel
            authStravaUrl={stravaUrl}
            demoLoginEnabled={demoLoginEnabled}
            onSubmitDemo={submitDemo}
            demoPending={demoForm.processing}
        />
    );

    return (
        <>
            <Head title="Temari · You vs Past You" />

            <Hero />

            {/* Connect card overlaps the hero's bottom edge, matching the
                prototype's single-panel placement (no side-by-side desktop
                grid, no duplicated CTA further down the page). */}
            <div className="relative z-10 -mt-10 px-5 sm:-mt-14 sm:px-8 lg:px-14">
                <div className="mx-auto max-w-md">{connect}</div>
            </div>

            <main className="mx-auto w-full max-w-page px-5 pb-16 sm:px-8 lg:px-14">
                <section className="mt-12">
                    <SectionLabel>how the comparison works</SectionLabel>
                    <h2 className="font-serif text-headline-sm text-foreground">
                        a verdict you can check the working on.
                    </h2>
                    <ul className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                        {MATCHING.map((item) => (
                            <FeatureCard key={item.label} {...item} />
                        ))}
                    </ul>
                </section>

                <section className="mt-12">
                    <SectionLabel>what you get</SectionLabel>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                        <Card className="flex items-center gap-5 px-6 py-6">
                            <Suspense
                                fallback={
                                    <div
                                        aria-hidden
                                        className="h-[150px] w-[140px] flex-none rounded-sm bg-cream-deep"
                                    />
                                }
                            >
                                <KartuMini
                                    name="10K Sunrise"
                                    rarity="legendary"
                                    mood="blazing"
                                    date="12 Jun"
                                    edition={{ index: 3, total: 12 }}
                                    polyline="~s{d@ofekSoRaMcPdMg@b^zFtV?bN{FtVf@b^bPdMnRaMlIqTdHqFfQcAfQcP?g[gQcPgQcAeHqFmIqT"
                                    className="shadow-e1"
                                />
                            </Suspense>
                            <div>
                                <p className="font-sans text-sm font-semibold text-foreground">
                                    this is a real card, not a mockup
                                </p>
                                <p className="mt-1.5 font-sans text-sm leading-relaxed text-text-2">
                                    every run that syncs earns one, route and
                                    all. some of them are rarer than others, and
                                    no, you cannot pick which.
                                </p>
                            </div>
                        </Card>
                        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-1">
                            {GETS.map((item) => (
                                <FeatureCard key={item.label} {...item} />
                            ))}
                        </ul>
                    </div>
                </section>

                {dataUse ? (
                    <section className="mt-12">
                        <SectionLabel>{dataUse.headline}</SectionLabel>
                        <Card className="px-6 py-6">
                            <ul className="flex flex-col gap-4">
                                {dataUse.points.map((point) => (
                                    <li
                                        key={point}
                                        className="font-sans text-sm leading-relaxed text-text-2"
                                    >
                                        {point}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </section>
                ) : null}

                {trainingDisclaimer ? (
                    <section className="mt-12">
                        <SectionLabel>before you take its advice</SectionLabel>
                        <Card className="px-6 py-6">
                            <p className="font-sans text-sm font-semibold text-foreground">
                                {trainingDisclaimer.headline}
                            </p>
                            <p className="mt-1.5 font-sans text-sm leading-relaxed text-text-2">
                                {trainingDisclaimer.text}
                            </p>
                            <a
                                href="/training-disclaimer"
                                className="focus-ring mt-3 inline-flex min-h-6 items-center gap-1 rounded font-sans text-sm text-foreground underline underline-offset-2 hover:text-text-2"
                            >
                                read the whole disclaimer
                                <Icon
                                    icon="mdi:arrow-right"
                                    width={14}
                                    height={14}
                                    aria-hidden
                                />
                            </a>
                        </Card>
                    </section>
                ) : null}

                <footer className="mt-12 flex flex-col items-center gap-3 border-t border-border pt-6">
                    <nav
                        aria-label="Legal"
                        className="flex flex-wrap justify-center gap-x-4 gap-y-1"
                    >
                        {LEGAL_LINKS.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                className="focus-ring inline-flex min-h-6 items-center rounded font-sans text-sm text-text-2 underline underline-offset-2 hover:text-foreground"
                            >
                                {link.label}
                            </a>
                        ))}
                    </nav>
                    <p className="text-center text-label-micro text-text-3">
                        temari · your running companion, every step
                    </p>
                </footer>
            </main>
        </>
    );
}

function FeatureCard({
    icon,
    label,
    desc,
}: Readonly<{ icon: string; label: string; desc: string }>) {
    return (
        <LegacyCard as="li" padding="card">
            <span
                aria-hidden
                className="mb-3 flex h-9 w-9 items-center justify-center rounded-lg bg-horizon/[0.18] text-horizon-ink"
            >
                <Icon icon={icon} width={20} height={20} aria-hidden />
            </span>
            <div className="font-sans text-sm font-semibold text-foreground">
                {label}
            </div>
            <p className="mt-1.5 font-sans text-sm leading-relaxed text-text-2">
                {desc}
            </p>
        </LegacyCard>
    );
}

function RouteEcho() {
    // Faint GPS-trace style curves behind the hero content. Calls back to running
    // brand without competing with the headline. Each traces itself in once on
    // mount, staggered slowly (route-draw + :nth-child delays in app.css) so it
    // reads as a route being laid down, not a pop-in. Plain CSS, not
    // framer-motion -- this route's entry-chunk budget stays clear of it (see
    // scripts/check-entry-chunks.mjs); prefers-reduced-motion is handled there too.
    return (
        <svg
            aria-hidden
            className="pointer-events-none absolute inset-0 h-full w-full"
            viewBox="0 0 800 800"
            preserveAspectRatio="xMidYMid slice"
            fill="none"
        >
            <path
                className="route-echo-path"
                pathLength={1}
                d="M-40,640 Q180,440 380,540 T880,320"
                stroke="white"
                strokeWidth="1.5"
                strokeLinecap="round"
                strokeOpacity="0.08"
            />
            <path
                className="route-echo-path"
                pathLength={1}
                d="M-40,540 Q140,340 340,420 T820,220"
                stroke="white"
                strokeWidth="1.2"
                strokeLinecap="round"
                strokeOpacity="0.06"
            />
            <path
                className="route-echo-path"
                pathLength={1}
                d="M-40,740 Q220,560 460,640 T920,460"
                stroke="white"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeOpacity="0.07"
            />
        </svg>
    );
}

function Hero() {
    return (
        <header
            className="relative overflow-hidden px-5 pb-20 pt-10 text-cream sm:px-8 sm:pb-24 lg:px-14"
            style={{ background: HERO_GRADIENT }}
        >
            <span
                aria-hidden
                className="hero-glow pointer-events-none absolute left-1/2 top-1/3 h-[560px] w-[560px] -translate-x-1/2 -translate-y-1/2 blur-sm"
                style={{ background: SUN_GLOW }}
            />
            <RouteEcho />

            <div className="login-fade-in-up relative z-10 mx-auto w-full max-w-page">
                <BrandMark tone="cream" />

                <div className="mt-10 max-w-2xl">
                    <Eyebrow token="hero" tone="horizon">
                        running companion
                    </Eyebrow>
                    <PageHero size="xl" onSky className="mt-3.5">
                        you vs
                        <br />
                        <em className="italic text-horizon">past you.</em>
                    </PageHero>
                    <p className="mt-5 max-w-xl font-sans text-base leading-relaxed text-cream/85">
                        every run you finish is matched against a run you have
                        already done. same kind of session, same sort of
                        distance, same you, on an earlier day. temari reads the
                        gap between them and tells you which way you are going,
                        with the number attached.
                    </p>
                    <p className="mt-5 max-w-xl font-serif italic text-quote-lg text-cream">
                        “no leaderboards, no strangers to lose to. just the
                        runner you were six weeks ago, and whether you have
                        caught them yet.”
                    </p>
                    <div className="mt-6 flex items-center gap-2.5 font-sans text-sm text-cream/75">
                        <TemariProto pose="glow" tone="sky" size={44} />
                        <span>
                            temari, who is going to keep score either way.
                        </span>
                    </div>
                </div>
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
        <Card className="bg-muted px-6 py-6 text-foreground shadow-e2">
            <div className="text-label-small text-foreground">
                start with your history
            </div>
            <p className="mt-2 font-sans text-sm leading-relaxed text-text-2">
                temari signs you in through Strava and reads the runs already
                sitting there. there is no separate account to make.
            </p>

            <a
                href={authStravaUrl}
                className="focus-ring relative mt-5 flex w-full items-center rounded-full bg-strava-orange py-3.5 text-sm font-semibold text-white transition hover:bg-strava-orange-hover"
            >
                <svg
                    viewBox="0 0 24 24"
                    className="absolute left-5 h-5 w-5"
                    fill="currentColor"
                    aria-hidden
                >
                    <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                </svg>
                <span className="flex-1 px-12 text-center">
                    connect with Strava
                </span>
            </a>

            {demoLoginEnabled && (
                <Button
                    type="button"
                    variant="ghost"
                    onClick={onSubmitDemo}
                    disabled={demoPending}
                    className="mt-2.5 h-auto w-full gap-1.5 px-0 py-2.5 text-sm font-semibold text-foreground"
                >
                    <Icon
                        icon="mdi:play-circle-outline"
                        width={16}
                        height={16}
                        aria-hidden
                    />
                    try the demo
                </Button>
            )}

            <p className="mt-5 font-sans text-sm leading-relaxed text-text-2">
                read only, and read for you alone. no other account can see a
                run of yours.{' '}
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

Login.layout = bareLayout;
