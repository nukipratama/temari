import { Icon } from '@iconify/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { lazy, Suspense } from 'react';

import type { SharedProps } from '@/types/inertia';

import BrandMark from '@/components/BrandMark';
import TemariProto from '@/components/temari/TemariProto';
import PillButton from '@/components/ui/PillButton';
import { bareLayout } from '@/layouts/BareShell';

// Lazy: KartuMini's rarity-chrome glyphs statically import framer-motion,
// which this route's entry-chunk budget must stay clear of.
const KartuMini = lazy(() => import('@/components/card/KartuMini'));

interface LoginProps {
    authStravaUrl: string;
    /** Deep link to return to after login (sanitized same-host path), or null. */
    from?: string | null;
}

const PILLARS: ReadonlyArray<{ icon: string; label: string; desc: string }> = [
    {
        icon: 'mdi:link-variant',
        label: 'I read 📖',
        desc: "I'll sync straight from your Strava, no extra steps.",
    },
    {
        icon: 'mdi:cards-outline',
        label: 'I record ✍️',
        desc: 'Every run earns its own card, win or easy day.',
    },
    {
        icon: 'mdi:hand-heart-outline',
        label: "I'm here for you 🫶",
        desc: 'I care that you showed up, not how fast you went.',
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

const FORM_CARD_SHADOW =
    '0 20px 50px rgba(36,28,84,0.06), 0 0 0 1px rgba(36,28,84,0.06)';

// Strava button keeps #FC4C02 brand orange and the official Strava glyph per their guidelines.
export default function Login({
    authStravaUrl,
    from = null,
}: Readonly<LoginProps>) {
    const { demoLoginEnabled, flash } = usePage<SharedProps>().props;
    const demoForm = useForm({ from });
    const submitDemo = () => demoForm.post('/auth/demo');

    const stravaUrl = from
        ? `${authStravaUrl}?from=${encodeURIComponent(from)}`
        : authStravaUrl;

    return (
        <>
            <Head title="Log in · Temari" />
            <div className="grid grid-cols-1 min-h-screen lg:grid-cols-[1.15fr_1fr]">
                <HeroSide />
                <FormSide
                    authStravaUrl={stravaUrl}
                    demoLoginEnabled={demoLoginEnabled}
                    onSubmitDemo={submitDemo}
                    demoPending={demoForm.processing}
                    info={flash?.info ?? null}
                />
            </div>
        </>
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

function HeroSide() {
    return (
        <div
            className="relative flex flex-col items-center justify-center overflow-hidden px-8 pb-12 pt-24 text-cream sm:px-12 lg:px-16 lg:py-[54px]"
            style={{ background: HERO_GRADIENT }}
        >
            <span
                aria-hidden
                className="hero-glow pointer-events-none absolute left-1/2 top-1/2 h-[560px] w-[560px] -translate-x-1/2 -translate-y-1/2 blur-sm"
                style={{ background: SUN_GLOW }}
            />
            <RouteEcho />

            <div className="absolute left-8 top-12 sm:left-12 lg:left-16 lg:top-[54px]">
                <BrandMark tone="cream" />
            </div>

            <div className="login-fade-in-up relative z-10 w-full max-w-[560px] text-center xl:max-w-[620px]">
                <div className="relative flex aspect-video w-full items-center justify-center overflow-hidden rounded-lg bg-sky-deep shadow-[0_24px_60px_rgba(0,0,0,0.45)] ring-1 ring-cream/15">
                    <TemariProto pose="glow" tone="sky" size={200} animate />
                </div>
                <h1 className="mt-7 font-display italic text-display-lg text-cream sm:text-display-xl">
                    <span className="block whitespace-nowrap">Your Run,</span>
                    <span className="block whitespace-nowrap text-horizon">
                        Never Alone.
                    </span>
                </h1>
                <p className="mt-4 font-sans text-base leading-relaxed text-cream sm:text-lg">
                    “Hi, I'm Temari. From now on, I'll be with you on every
                    run.”
                </p>
            </div>
        </div>
    );
}

interface FormSideProps {
    authStravaUrl: string;
    demoLoginEnabled: boolean;
    onSubmitDemo: () => void;
    demoPending: boolean;
    info?: string | null;
}

function FormSide({
    authStravaUrl,
    demoLoginEnabled,
    onSubmitDemo,
    demoPending,
    info = null,
}: Readonly<FormSideProps>) {
    return (
        <div className="login-fade-in-up flex flex-col items-center justify-center gap-7 bg-cream px-8 py-12 sm:px-12 lg:px-[100px] lg:py-20">
            {info && (
                <div
                    role="status"
                    className="flex w-full max-w-[480px] items-start gap-2.5 rounded-lg border border-leaf/30 bg-leaf/[0.08] px-4 py-3 font-sans text-[13px] leading-relaxed text-ink-2 2xl:max-w-[560px]"
                >
                    <Icon
                        icon="mdi:check-circle-outline"
                        width={16}
                        height={16}
                        aria-hidden
                        className="mt-0.5 shrink-0 text-leaf-deep"
                    />
                    <span>{info}</span>
                </div>
            )}
            <ul className="grid w-full max-w-[480px] grid-cols-3 gap-3.5 2xl:max-w-[560px]">
                {PILLARS.map((pillar) => (
                    <li
                        key={pillar.label}
                        className="rounded-[10px] border border-cream-deep bg-cream px-4 py-4"
                    >
                        <span
                            aria-hidden
                            className="mb-2.5 flex h-8 w-8 items-center justify-center rounded-lg bg-horizon/[0.18] text-horizon-deep"
                        >
                            <Icon
                                icon={pillar.icon}
                                width={18}
                                height={18}
                                aria-hidden
                            />
                        </span>
                        <div className="font-sans text-sm font-semibold text-ink">
                            {pillar.label}
                        </div>
                        <div className="mt-1 font-sans text-xs leading-snug text-ink-3">
                            {pillar.desc}
                        </div>
                    </li>
                ))}
            </ul>

            <div className="flex w-full max-w-[480px] items-center gap-4 rounded-lg border border-cream-deep bg-cream px-4 py-4 2xl:max-w-[560px]">
                <Suspense
                    fallback={
                        <div
                            aria-hidden
                            className="h-[150px] w-[140px] flex-none rounded-[12px] bg-cream-deep"
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
                    <p className="mt-1 font-sans text-xs leading-relaxed text-ink-3">
                        For every run that syncs from your Strava, Temari makes
                        a collectible card just like this one, complete with the
                        route and that day's mood.
                    </p>
                </div>
            </div>

            <div
                className="w-full max-w-[480px] rounded-lg bg-cream px-9 py-10 2xl:max-w-[560px]"
                style={{ boxShadow: FORM_CARD_SHADOW }}
            >
                <h2 className="font-display italic text-display-xs text-ink">
                    Welcome.
                </h2>
                <p className="mt-2.5 font-sans text-sm leading-relaxed text-ink-2">
                    Connect your Strava first. I'm waiting inside.
                </p>

                <a
                    href={authStravaUrl}
                    className="relative mt-6 flex w-full items-center rounded-full bg-strava-orange py-3.5 text-sm font-semibold text-white transition hover:bg-strava-orange-hover focus:outline-none focus:ring-4 focus:ring-strava-orange/30"
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
                        className="relative mt-2.5 flex w-full items-center bg-transparent px-0 py-3 text-sm text-ink hover:text-ink disabled:opacity-60"
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

                <p className="mt-6 flex items-start gap-2.5 rounded-[10px] bg-leaf/10 px-4 py-3 font-sans text-[13px] leading-relaxed text-ink-2">
                    <Icon
                        icon="mdi:shield-check-outline"
                        width={16}
                        height={16}
                        aria-hidden
                        className="mt-0.5 shrink-0 text-leaf-deep"
                    />
                    <span>
                        I only use Strava to read your runs, nothing else.
                    </span>
                </p>
            </div>

            <p className="text-center text-label-micro text-ink-3">
                I'll see you on the other side of that button. 🐾
            </p>

            <nav
                aria-label="Legal"
                className="flex flex-wrap justify-center gap-x-4 gap-y-1"
            >
                {LEGAL_LINKS.map((link) => (
                    <a
                        key={link.href}
                        href={link.href}
                        className="font-sans text-xs text-ink-3 underline underline-offset-2 hover:text-ink-2"
                    >
                        {link.label}
                    </a>
                ))}
            </nav>
        </div>
    );
}

Login.layout = bareLayout;
