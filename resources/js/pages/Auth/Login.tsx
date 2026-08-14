import { Icon } from '@iconify/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { lazy, Suspense, type ReactNode } from 'react';

import type { SharedProps } from '@/types/inertia';

import BrandMark from '@/components/BrandMark';
import TemariProto from '@/components/temari/TemariProto';
import Card from '@/components/ui/Card';
import Eyebrow from '@/components/ui/Eyebrow';
import PageHero from '@/components/ui/PageHero';
import PillButton from '@/components/ui/PillButton';
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
        label: 'It finds a fair match',
        desc: 'Same pace band, within 500 m of the distance, three weeks to a year back. A run that is not comparable does not get used against you.',
    },
    {
        icon: 'mdi:swap-vertical-bold',
        label: 'It reads the gap, not the vibe',
        desc: 'Pace calls it once the gap clears the noise. When pace comes back flat, heart rate calls it, so the same pace at a higher HR counts as a loss.',
    },
    {
        icon: 'mdi:help-rhombus-outline',
        label: 'It says when it cannot tell',
        desc: 'Under two fair pairings and the answer is "not enough history yet", plus how close you are to one. No trend gets invented to fill the space.',
    },
];

const GETS: ReadonlyArray<{ icon: string; label: string; desc: string }> = [
    {
        icon: 'mdi:cards-outline',
        label: 'A card for every run',
        desc: 'Your route, your pace, the mood of the day. Collectible, and occasionally rare.',
    },
    {
        icon: 'mdi:calendar-check-outline',
        label: 'A plan that answers to your week',
        desc: 'Built from the volume you actually ran, not the volume you meant to run.',
    },
    {
        icon: 'mdi:trophy-outline',
        label: 'Records and recaps',
        desc: 'Your PRs, your weeks, your months. Each one comes with the number attached.',
    },
];

// Plain anchors, not Inertia <Link>: these are the pages a stranger reads
// before deciding to connect a Strava account, so they must survive the SPA
// runtime failing to boot at all.
const LEGAL_LINKS: ReadonlyArray<{ href: string; label: string }> = [
    { href: '/terms', label: 'Terms' },
    { href: '/privacy', label: 'Privacy' },
    { href: '/ai-use', label: 'How Temari uses AI' },
    { href: '/training-disclaimer', label: 'Training disclaimer' },
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
    const { demoLoginEnabled, flash } = usePage<SharedProps>().props;
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

            <Hero info={flash?.info ?? null}>{connect}</Hero>

            <main className="mx-auto w-full max-w-page px-5 pb-16 sm:px-8 lg:px-14">
                <section className="mt-12">
                    <SectionLabel>How the comparison works</SectionLabel>
                    <h2 className="font-display text-headline-sm text-ink">
                        A verdict you can check the working on.
                    </h2>
                    <ul className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                        {MATCHING.map((item) => (
                            <FeatureCard key={item.label} {...item} />
                        ))}
                    </ul>
                </section>

                <section className="mt-12">
                    <SectionLabel>What you get</SectionLabel>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                        <Card
                            padding="hero"
                            className="flex items-center gap-5"
                        >
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
                                <p className="font-sans text-sm font-semibold text-ink">
                                    This is a real card, not a mockup
                                </p>
                                <p className="mt-1.5 font-sans text-sm leading-relaxed text-ink-2">
                                    Every run that syncs earns one, route and
                                    all. Some of them are rarer than others, and
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
                        <Card padding="hero">
                            <ul className="flex flex-col gap-4">
                                {dataUse.points.map((point) => (
                                    <li
                                        key={point}
                                        className="font-sans text-sm leading-relaxed text-ink-2"
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
                        <SectionLabel>Before you take its advice</SectionLabel>
                        <Card padding="hero">
                            <p className="font-sans text-sm font-semibold text-ink">
                                {trainingDisclaimer.headline}
                            </p>
                            <p className="mt-1.5 font-sans text-sm leading-relaxed text-ink-2">
                                {trainingDisclaimer.text}
                            </p>
                            <a
                                href="/training-disclaimer"
                                className="focus-ring mt-3 inline-flex min-h-6 items-center gap-1 rounded font-sans text-sm text-ink underline underline-offset-2 hover:text-ink-2"
                            >
                                Read the whole disclaimer
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

                <section className="mt-12">
                    <Card
                        padding="hero"
                        className="flex flex-col items-center gap-5 text-center"
                    >
                        <PageHero size="sm" className="text-center">
                            Ready when you are.
                        </PageHero>
                        <p className="max-w-md font-sans text-sm leading-relaxed text-ink-2">
                            Connecting Strava is the whole setup. Temari reads
                            what is already there and starts keeping score from
                            your first sync.
                        </p>
                        <div className="w-full max-w-sm">{connect}</div>
                    </Card>
                </section>

                <footer className="mt-10 flex flex-col items-center gap-3 border-t border-line pt-6">
                    <nav
                        aria-label="Legal"
                        className="flex flex-wrap justify-center gap-x-4 gap-y-1"
                    >
                        {LEGAL_LINKS.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                className="focus-ring inline-flex min-h-6 items-center rounded font-sans text-sm text-ink-2 underline underline-offset-2 hover:text-ink"
                            >
                                {link.label}
                            </a>
                        ))}
                    </nav>
                    <p className="text-center text-label-micro text-ink-3">
                        Temari · your running companion, every step
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
        <Card as="li" padding="card">
            <span
                aria-hidden
                className="mb-3 flex h-9 w-9 items-center justify-center rounded-sm bg-horizon/[0.18] text-horizon-ink"
            >
                <Icon icon={icon} width={20} height={20} aria-hidden />
            </span>
            <div className="font-sans text-sm font-semibold text-ink">
                {label}
            </div>
            <p className="mt-1.5 font-sans text-sm leading-relaxed text-ink-2">
                {desc}
            </p>
        </Card>
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

function Hero({
    info,
    children,
}: Readonly<{ info: string | null; children: ReactNode }>) {
    return (
        <header
            className="relative overflow-hidden px-5 pb-14 pt-10 text-cream sm:px-8 lg:px-14"
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

                {info && (
                    <div
                        role="status"
                        className="mt-6 flex items-start gap-2.5 rounded-sm border border-cream/20 bg-cream/[0.08] px-4 py-3 font-sans text-sm leading-relaxed text-cream"
                    >
                        <Icon
                            icon="mdi:check-circle-outline"
                            width={16}
                            height={16}
                            aria-hidden
                            className="mt-0.5 shrink-0"
                        />
                        <span>{info}</span>
                    </div>
                )}

                <div className="mt-10 grid grid-cols-1 items-center gap-10 lg:grid-cols-[1.15fr_minmax(0,26rem)]">
                    <div>
                        <Eyebrow token="hero" tone="horizon">
                            Running companion
                        </Eyebrow>
                        <PageHero size="xl" onSky className="mt-3.5">
                            You vs{' '}
                            <em className="italic text-horizon">past you.</em>
                        </PageHero>
                        <p className="mt-5 max-w-xl font-sans text-base leading-relaxed text-cream/85">
                            Every run you finish is matched against a run you
                            have already done. Same kind of session, same sort
                            of distance, same you, on an earlier day. Temari
                            reads the gap between them and tells you which way
                            you are going, with the number attached.
                        </p>
                        <p className="mt-5 max-w-xl font-display italic text-quote-lg text-cream">
                            “no leaderboards, no strangers to lose to. just the
                            runner you were six weeks ago, and whether you have
                            caught them yet.”
                        </p>
                        <div className="mt-6 flex items-center gap-2.5 font-sans text-sm text-cream/75">
                            <TemariProto pose="glow" tone="sky" size={44} />
                            <span>
                                Temari, who is going to keep score either way.
                            </span>
                        </div>
                    </div>

                    <div className="w-full">{children}</div>
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
        <Card padding="hero" className="bg-surface-sunken text-ink">
            <h2 className="font-display italic text-display-xs text-ink">
                Start with your history.
            </h2>
            <p className="mt-2 font-sans text-sm leading-relaxed text-ink-2">
                Temari signs you in through Strava and reads the runs already
                sitting there. There is no separate account to make.
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
                    Connect with Strava
                </span>
            </a>

            {demoLoginEnabled && (
                <PillButton
                    tone="outline"
                    onClick={onSubmitDemo}
                    disabled={demoPending}
                    className="relative mt-2.5 flex w-full items-center bg-transparent px-0 py-3 text-sm text-ink hover:text-ink"
                >
                    <Icon
                        icon="mdi:play-circle-outline"
                        width={16}
                        height={16}
                        aria-hidden
                        className="absolute left-5"
                    />
                    <span className="flex-1 px-12 text-center">
                        Try the demo
                    </span>
                </PillButton>
            )}

            <p className="mt-5 font-sans text-sm leading-relaxed text-ink-2">
                Read only, and read for you alone. No other account can see a
                run of yours.{' '}
                <a
                    href="/privacy"
                    className="focus-ring rounded text-ink underline underline-offset-2 hover:text-ink-2"
                >
                    What Temari stores
                </a>
            </p>
        </Card>
    );
}

Login.layout = bareLayout;
