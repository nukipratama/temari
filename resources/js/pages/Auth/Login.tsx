import { Head, useForm, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { useRef, useState } from 'react';
import AppShell from '@/layouts/AppShell';
import BrandMark from '@/components/BrandMark';
import KartuMini from '@/components/card/KartuMini';
import PillButton from '@/components/ui/PillButton';
import type { SharedProps } from '@/types/inertia';

interface LoginProps {
    authStravaUrl: string;
    /** Deep link to return to after login (sanitized same-host path), or null. */
    from?: string | null;
}

const PILLARS: ReadonlyArray<{ icon: string; label: string; desc: string }> = [
    { icon: 'mdi:link-variant', label: 'Aku baca 📖', desc: 'Strava-mu nyambung otomatis' },
    { icon: 'mdi:cards-outline', label: 'Aku catat ✍️', desc: 'Tiap lari dapet kartunya' },
    { icon: 'mdi:hand-heart-outline', label: 'Aku temenin 🫶', desc: 'Konsisten, bukan kenceng' },
];

const HERO_GRADIENT =
    'linear-gradient(180deg, var(--color-sky-deep) 0%, var(--color-sky) 38%, var(--color-sky-2) 62%, oklch(58% 0.10 38) 82%, var(--color-horizon-deep) 100%)';

const SUN_GLOW =
    'radial-gradient(circle, oklch(80% 0.14 55 / 0.6) 0%, oklch(72% 0.13 50 / 0.25) 28%, transparent 58%)';

const FORM_CARD_SHADOW =
    '0 20px 50px rgba(31,39,71,0.06), 0 0 0 1px rgba(31,39,71,0.06)';

// Strava button keeps #FC4C02 brand orange and the official Strava glyph per their guidelines.
// Button label is localized ("Sambungkan dengan Strava") per explicit product decision; accept
// the small risk that Strava brand review may flag it.
export default function Login({ authStravaUrl, from = null }: Readonly<LoginProps>) {
    const { demoLoginEnabled } = usePage<SharedProps>().props;
    const demoForm = useForm({ from });
    const submitDemo = () => demoForm.post('/auth/demo');

    const stravaUrl = from ? `${authStravaUrl}?from=${encodeURIComponent(from)}` : authStravaUrl;

    return (
        <AppShell withNav={false}>
            <Head title="Masuk · TemanLari" />
            <div className="grid grid-cols-1 min-h-screen lg:grid-cols-[1.05fr_1fr]">
                <HeroSide />
                <FormSide
                    authStravaUrl={stravaUrl}
                    demoLoginEnabled={demoLoginEnabled}
                    onSubmitDemo={submitDemo}
                    demoPending={demoForm.processing}
                />
            </div>
        </AppShell>
    );
}

function RouteEcho() {
    // Faint GPS-trace style curves behind the hero content. Calls back to running
    // brand without competing with the headline. Pure SVG, no animation, very low opacity.
    return (
        <svg
            aria-hidden
            className="pointer-events-none absolute inset-0 h-full w-full"
            viewBox="0 0 800 800"
            preserveAspectRatio="xMidYMid slice"
            fill="none"
        >
            <path d="M-40,640 Q180,440 380,540 T880,320" stroke="white" strokeWidth="1.5" strokeLinecap="round" strokeOpacity="0.08" />
            <path d="M-40,540 Q140,340 340,420 T820,220" stroke="white" strokeWidth="1.2" strokeLinecap="round" strokeOpacity="0.06" />
            <path d="M-40,740 Q220,560 460,640 T920,460" stroke="white" strokeWidth="1.8" strokeLinecap="round" strokeOpacity="0.07" />
        </svg>
    );
}

function HeroSide() {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [playing, setPlaying] = useState(false);
    // Click-to-play with sound: the click is the user gesture browsers require to
    // allow audio, so the narrated ad plays unmuted. No autoplay (it's a 2.5min story).
    // Only hide the overlay after play() resolves — if the browser rejects the call
    // (e.g. interrupted by a second click) the button stays visible so the user can retry.
    const playIntro = () => {
        videoRef.current?.play().then(() => setPlaying(true)).catch(() => {});
    };
    return (
        <div
            className="relative flex flex-col items-center justify-center overflow-hidden px-8 pb-12 pt-24 text-cream sm:px-12 lg:px-16 lg:py-[54px]"
            style={{ background: HERO_GRADIENT }}
        >
            <span
                aria-hidden
                className="pointer-events-none absolute left-1/2 top-1/2 h-[560px] w-[560px] -translate-x-1/2 -translate-y-1/2 blur-sm"
                style={{ background: SUN_GLOW }}
            />
            <RouteEcho />

            <div className="absolute left-8 top-12 sm:left-12 lg:left-16 lg:top-[54px]">
                <BrandMark tone="cream" />
            </div>

            <div className="relative z-10 w-full max-w-[560px] text-center xl:max-w-[620px]">
                <div className="relative overflow-hidden rounded-2xl shadow-[0_24px_60px_rgba(0,0,0,0.45)] ring-1 ring-cream/15">
                    <video
                        ref={videoRef}
                        src="/videos/intro.mp4"
                        poster="/videos/intro-poster.jpg"
                        controls={playing}
                        playsInline
                        preload="metadata"
                        className="block aspect-video w-full bg-sky-deep"
                        onEnded={() => setPlaying(false)}
                    >
                        <track kind="captions" />
                    </video>
                    {!playing && (
                        <button
                            type="button"
                            onClick={playIntro}
                            aria-label="Putar video intro"
                            className="focus-ring-on-sky group absolute inset-0 flex items-center justify-center bg-sky-deep/25 transition hover:bg-sky-deep/10"
                        >
                            <span className="flex h-16 w-16 items-center justify-center rounded-full bg-cream/95 shadow-lg transition group-hover:scale-105">
                                <Icon icon="mdi:play" width={32} height={32} className="ml-1 text-sky" aria-hidden />
                            </span>
                        </button>
                    )}
                </div>
                <h1 className="mt-7 font-display italic text-display-lg text-cream sm:text-display-xl">
                    <span className="block whitespace-nowrap">Lari Kamu,</span>
                    <span className="block whitespace-nowrap text-horizon">Gak Sendirian.</span>
                </h1>
                <p className="mt-4 font-sans text-base leading-relaxed text-cream sm:text-lg">
                    “Halo, aku Temari. Mulai sekarang, lari kamu aku temenin.”
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
}

function FormSide({ authStravaUrl, demoLoginEnabled, onSubmitDemo, demoPending }: Readonly<FormSideProps>) {
    return (
        <div className="flex flex-col items-center justify-center gap-9 bg-cream px-8 py-12 sm:px-12 lg:px-[100px] lg:py-20">
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
                            <Icon icon={pillar.icon} width={18} height={18} aria-hidden />
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

            <div className="flex w-full max-w-[480px] items-center gap-4 rounded-2xl border border-cream-deep bg-cream px-4 py-4 2xl:max-w-[560px]">
                <KartuMini
                    name="10K Subuh"
                    rarity="legendary"
                    mood="nyala"
                    date="12 Jun"
                    edition={{ index: 3, total: 12 }}
                    polyline="~s{d@ofekSoRaMcPdMg@b^zFtV?bN{FtVf@b^bPdMnRaMlIqTdHqFfQcAfQcP?g[gQcPgQcAeHqFmIqT"
                    className="shadow-md"
                />
                <div>
                    <p className="font-sans text-sm font-semibold text-ink">Ini kartu beneran, bukan mockup</p>
                    <p className="mt-1 font-sans text-xs leading-relaxed text-ink-3">
                        Tiap lari yang nyambung dari Strava-mu, Temari bikinin kartu koleksi kayak gini, lengkap sama rute dan mood hari itu.
                    </p>
                </div>
            </div>

            <div
                className="w-full max-w-[480px] rounded-2xl bg-cream px-9 py-10 2xl:max-w-[560px]"
                style={{ boxShadow: FORM_CARD_SHADOW }}
            >
                <h2 className="font-display italic text-display-xs text-ink">
                    Selamat datang.
                </h2>
                <p className="mt-2.5 font-sans text-sm leading-relaxed text-ink-2">
                    Sambungin Strava-mu dulu. Temari udah nunggu di dalem.
                </p>

                <a
                    href={authStravaUrl}
                    className="relative mt-6 flex w-full items-center rounded-full bg-strava-orange py-3.5 text-sm font-semibold text-white transition hover:bg-strava-orange-hover focus:outline-none focus:ring-4 focus:ring-strava-orange/30"
                >
                    <svg viewBox="0 0 24 24" className="absolute left-5 h-5 w-5" fill="currentColor" aria-hidden>
                        <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                    </svg>
                    <span className="flex-1 px-12 text-center">Sambungkan dengan Strava</span>
                </a>

                {demoLoginEnabled && (
                    <PillButton
                        tone="outline"
                        onClick={onSubmitDemo}
                        disabled={demoPending}
                        className="relative mt-2.5 flex w-full items-center bg-transparent px-0 py-3 text-sm text-ink hover:text-ink disabled:opacity-60"
                    >
                        <Icon icon="mdi:play-circle-outline" width={16} height={16} aria-hidden className="absolute left-5" />
                        <span className="flex-1 px-12 text-center">Coba versi demo</span>
                    </PillButton>
                )}

                <p className="mt-6 flex items-start gap-2.5 rounded-[10px] bg-leaf/10 px-4 py-3 font-sans text-[13px] leading-relaxed text-ink-2">
                    <Icon icon="mdi:shield-check-outline" width={16} height={16} aria-hidden className="mt-0.5 shrink-0 text-leaf-deep" />
                    <span>Aku cuma pake Strava buat baca lari kamu doang, bukan yang lain.</span>
                </p>
            </div>

            <p className="text-center text-label-micro text-ink-3">
                Dibuat dengan ♥ oleh pelari, buat pelari
            </p>
        </div>
    );
}
