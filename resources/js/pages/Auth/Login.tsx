import { Head, useForm, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AppShell from '@/layouts/AppShell';
import TemariProto from '@/components/daybreak/TemariProto';
import type { SharedProps } from '@/types/inertia';

interface LoginProps {
    authStravaUrl: string;
}

const PILLARS: ReadonlyArray<{ icon: string; label: string; desc: string }> = [
    { icon: 'mdi:cloud-download-outline', label: 'Catat', desc: 'Otomatis nyambung dari Strava' },
    { icon: 'mdi:card-account-details-outline', label: 'Kasih', desc: 'Tiap lari, Temari bikinin kartunya' },
    { icon: 'mdi:calendar-check', label: 'Konsisten', desc: 'Jalan terus, nggak harus kenceng' },
];

// Strava button keeps #FC4C02 brand orange per Strava brand guidelines.
export default function Login({ authStravaUrl }: Readonly<LoginProps>) {
    const { demoLoginEnabled } = usePage<SharedProps>().props;
    const demoForm = useForm({});
    const submitDemo = () => demoForm.post('/auth/demo');

    return (
        <AppShell withNav={false}>
            <Head title="Masuk · TemanLari" />
            <div className="grid min-h-screen lg:grid-cols-[1fr_1fr]">
                {/* LEFT — sky-gradient hero */}
                <div
                    className="relative flex flex-col justify-between overflow-hidden px-8 py-12 text-cream sm:px-12 lg:px-16 lg:py-16"
                    style={{
                        background:
                            'linear-gradient(165deg, var(--color-sky-deep) 0%, var(--color-sky) 55%, var(--color-sky-2) 100%)',
                    }}
                >
                    {/* Sunrise horizon glow — sits behind everything to evoke the pre-dawn ember. */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0"
                        style={{
                            background:
                                'radial-gradient(ellipse at 70% 100%, rgba(232,160,118,0.55) 0%, rgba(232,160,118,0.18) 35%, transparent 60%)',
                        }}
                    />
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -right-10 top-20 h-72 w-72 rounded-full"
                        style={{ background: 'radial-gradient(circle, rgba(232,160,118,0.4) 0%, transparent 65%)' }}
                    />
                    <div className="relative">
                        <div className="font-mono text-[11px] uppercase tracking-[0.22em] text-cream/70">
                            TemanLari
                        </div>
                        <h1 className="mt-3 font-display text-[44px] leading-[0.95] tracking-[-0.02em] sm:text-[64px] lg:text-[80px] lg:leading-[0.92]">
                            Setiap Langkah<br />
                            <em className="italic text-horizon">Berarti.</em>
                        </h1>
                    </div>
                    <div className="relative flex items-end justify-center">
                        <TemariProto pose="proud" size={220} />
                    </div>
                    <p className="relative font-display text-base italic text-cream/70 sm:text-lg">
                        “Dari Temari, untuk kamu — biar lari nggak terasa sendirian.”
                    </p>
                </div>

                {/* RIGHT — cream form */}
                <div className="flex flex-col justify-center bg-cream px-8 py-12 sm:px-12 lg:px-16">
                    <div className="mx-auto w-full max-w-md">
                        <div className="mb-6">
                            <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3">
                                Masuk
                            </div>
                            <h2 className="mt-2 font-display text-[34px] leading-tight tracking-[-0.015em] text-ink sm:text-[44px]">
                                Selamat datang <em className="italic text-horizon-deep">lagi.</em>
                            </h2>
                            <p className="mt-3 font-sans text-sm leading-relaxed text-ink-2">
                                Sambungkan ke Strava — Temari mulai catat dari sini.
                            </p>
                        </div>

                        <ul className="mb-8 grid grid-cols-3 gap-2">
                            {PILLARS.map((pillar) => (
                                <li
                                    key={pillar.label}
                                    className="flex flex-col gap-1.5 rounded-xl bg-cream-deep px-3 py-3.5"
                                >
                                    <Icon
                                        icon={pillar.icon}
                                        width={18}
                                        height={18}
                                        aria-hidden
                                        className="text-horizon-deep"
                                    />
                                    <div className="font-display text-base italic leading-none text-ink">
                                        {pillar.label}
                                    </div>
                                    <div className="font-sans text-[11px] leading-snug text-ink-3">
                                        {pillar.desc}
                                    </div>
                                </li>
                            ))}
                        </ul>

                        <a
                            href={authStravaUrl}
                            className="inline-flex w-full items-center justify-center gap-2.5 rounded-xl bg-strava-orange px-5 py-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-strava-orange-hover focus:outline-none focus:ring-4 focus:ring-strava-orange/30"
                        >
                            <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor" aria-hidden>
                                <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                            </svg>
                            Connect with Strava
                        </a>

                        {demoLoginEnabled && (
                            <button
                                type="button"
                                onClick={submitDemo}
                                disabled={demoForm.processing}
                                className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-cream-deep bg-cream px-5 py-3 text-sm font-semibold text-ink transition hover:border-ink-3 focus:outline-none focus:ring-4 focus:ring-horizon/20"
                            >
                                <Icon icon="mdi:play-circle-outline" width={18} height={18} aria-hidden />
                                Coba versi demo
                            </button>
                        )}

                        <p className="mt-6 font-display text-xs italic leading-relaxed text-ink-3">
                            Temari cuma baca aktivitas lari kamu — gak yang lain.
                        </p>
                    </div>
                </div>
            </div>
        </AppShell>
    );
}
