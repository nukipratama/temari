import { Head, useForm, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AppShell from '@/layouts/AppShell';
import BrandMark from '@/components/BrandMark';
import TemariMascot from '@/components/temari/TemariMascot';
import TemariProto from '@/components/temari/TemariProto';
import type { SharedProps } from '@/types/inertia';

interface LoginProps {
    authStravaUrl: string;
}

const PILLARS: ReadonlyArray<{ icon: string; label: string; desc: string }> = [
    { icon: 'mdi:link-variant', label: 'Aku baca', desc: 'Strava-mu nyambung otomatis' },
    { icon: 'mdi:cards-outline', label: 'Aku catet', desc: 'Tiap lari dapet kartunya' },
    { icon: 'mdi:hand-heart-outline', label: 'Aku temenin', desc: 'Konsisten, bukan kenceng' },
];

const HERO_GRADIENT =
    'linear-gradient(180deg, var(--color-sky-deep) 0%, var(--color-sky) 38%, var(--color-sky-2) 62%, oklch(58% 0.10 38) 82%, var(--color-horizon-deep) 100%)';

const SUN_GLOW =
    'radial-gradient(circle, oklch(80% 0.14 55 / 0.6) 0%, oklch(72% 0.13 50 / 0.25) 28%, transparent 58%)';

const HORIZON_BAND =
    'linear-gradient(90deg, transparent, oklch(82% 0.12 55 / 0.55), transparent)';

const FORM_CARD_SHADOW =
    '0 20px 50px rgba(31,39,71,0.06), 0 0 0 1px rgba(31,39,71,0.06)';

// Strava button keeps #FC4C02 brand orange per Strava brand guidelines.
export default function Login({ authStravaUrl }: Readonly<LoginProps>) {
    const { demoLoginEnabled } = usePage<SharedProps>().props;
    const demoForm = useForm({});
    const submitDemo = () => demoForm.post('/auth/demo');

    return (
        <AppShell withNav={false}>
            <Head title="Masuk · TemanLari" />
            <div className="grid min-h-screen lg:grid-cols-[1.05fr_1fr]">
                <HeroSide />
                <FormSide
                    authStravaUrl={authStravaUrl}
                    demoLoginEnabled={demoLoginEnabled}
                    onSubmitDemo={submitDemo}
                    demoPending={demoForm.processing}
                />
            </div>
        </AppShell>
    );
}

function HeroSide() {
    return (
        <div
            className="relative flex flex-col justify-between overflow-hidden px-8 py-12 text-cream sm:px-12 lg:px-16 lg:py-[54px]"
            style={{ background: HERO_GRADIENT }}
        >
            <span
                aria-hidden
                className="pointer-events-none absolute left-1/2 h-[560px] w-[560px] -translate-x-1/2 blur-sm"
                style={{ bottom: '20%', background: SUN_GLOW }}
            />
            <span
                aria-hidden
                className="pointer-events-none absolute inset-x-0 h-px"
                style={{ bottom: '22%', background: HORIZON_BAND }}
            />

            <BrandMark tone="cream" />

            <div className="relative z-10 text-center">
                <div className="mb-8 flex justify-center">
                    <TemariProto pose="proud" size={220} />
                </div>
                <h1 className="font-display italic text-display-xl">
                    Setiap Langkah<br /><span>Berarti.</span>
                </h1>
                <p className="mx-auto mt-6 max-w-[520px] font-sans text-base leading-relaxed text-cream/90 sm:text-lg">
                    “Halo, aku Temari — temen lari kamu. Tiap kamu lari, aku baca, terus aku kasih kartunya.”
                </p>
            </div>

            <div
                aria-hidden
                className="pointer-events-none absolute bottom-6 right-6 hidden opacity-[0.55] mix-blend-screen sm:right-10 sm:block lg:bottom-8 lg:right-12"
            >
                <TemariMascot mood="enteng" sizeClass="h-24 w-24 lg:h-28 lg:w-28" idle="mood" showUnlocks={false} />
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
        <div className="flex flex-col justify-center gap-9 bg-cream px-8 py-12 sm:px-12 lg:px-[100px] lg:py-20">
            <ul className="grid grid-cols-3 gap-3.5">
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

            <div
                className="rounded-2xl bg-cream px-9 py-10"
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
                    className="mt-7 inline-flex w-full items-center justify-center gap-2.5 rounded-full bg-strava-orange px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-strava-orange-hover focus:outline-none focus:ring-4 focus:ring-strava-orange/30"
                >
                    <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor" aria-hidden>
                        <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                    </svg>
                    Connect with Strava
                </a>

                {demoLoginEnabled && (
                    <button
                        type="button"
                        onClick={onSubmitDemo}
                        disabled={demoPending}
                        className="mt-2.5 inline-flex w-full items-center justify-center gap-2 rounded-full border-[1.5px] border-cream-deep bg-transparent px-5 py-3 text-sm font-medium text-ink transition hover:border-ink-3 focus:outline-none focus:ring-4 focus:ring-horizon/20 disabled:opacity-60"
                    >
                        <Icon icon="mdi:play-circle-outline" width={16} height={16} aria-hidden />
                        Coba versi demo
                    </button>
                )}

                <p className="mt-6 flex items-start gap-2.5 rounded-[10px] bg-leaf/10 px-4 py-3 font-sans text-[13px] leading-relaxed text-ink-2">
                    <Icon icon="mdi:shield-check-outline" width={16} height={16} aria-hidden className="mt-0.5 shrink-0 text-leaf-deep" />
                    <span>Aku cuma pake Strava buat baca lari kamu doang — bukan yang lain.</span>
                </p>
            </div>

            <p className="text-center font-mono text-[10px] uppercase tracking-[0.14em] text-ink-3">
                Dibuat dengan ♥ oleh pelari, buat pelari
            </p>
        </div>
    );
}
