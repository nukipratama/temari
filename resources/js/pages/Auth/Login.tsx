import { Head, useForm, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import AppShell from '@/layouts/AppShell';
import BrandMark from '@/components/BrandMark';
import type { SharedProps } from '@/types/inertia';

interface LoginProps {
    authStravaUrl: string;
}

const FEATURES: ReadonlyArray<{ icon: string; label: string; desc: string }> = [
    { icon: 'mdi:cloud-download-outline', label: 'Catat', desc: 'Setiap lari, otomatis dari Strava' },
    { icon: 'mdi:chart-line', label: 'Pantau', desc: 'Lihat progress mingguan' },
    { icon: 'mdi:calendar-check', label: 'Konsisten', desc: 'Bangun kebiasaan, bukan target' },
];

/**
 * Pre-auth landing. Leads with the TemanLari brand — the mascot Temari
 * stays inside the authenticated app where users meet her on the dashboard
 * after their first sync. Strava button keeps its #FC4C02 brand orange per
 * Strava guidelines; everything else uses Hutan Pagi tokens.
 */
export default function Login({ authStravaUrl }: Readonly<LoginProps>) {
    const { props } = usePage<SharedProps & LoginProps>();
    const demoForm = useForm({});
    const submitDemo = () => demoForm.post('/auth/demo');

    return (
        <AppShell showHeader={false}>
            <Head title="Masuk" />
            <div className="relative min-h-screen overflow-hidden">
                <div className="pointer-events-none absolute inset-0 -z-10">
                    <div className="absolute -right-32 -top-40 h-[28rem] w-[28rem] rounded-full bg-brand-400 opacity-20 blur-3xl" />
                    <div className="absolute -bottom-40 -left-32 h-[28rem] w-[28rem] rounded-full bg-accent-400 opacity-15 blur-3xl" />
                </div>

                <main className="relative mx-auto flex min-h-screen w-full max-w-3xl flex-col justify-center px-6 py-12">
                    <BrandMark tagline className="mb-10" />

                    <section className="mb-10 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        {FEATURES.map((feature) => (
                            <div
                                key={feature.label}
                                className="flex flex-col items-center rounded-2xl border border-line bg-surface-elev/60 p-5 text-center backdrop-blur dark:border-line-dark dark:bg-surface-dark-elev/60"
                            >
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                                    <Icon icon={feature.icon} width={22} height={22} aria-hidden />
                                </div>
                                <h2 className="mt-4 text-sm font-semibold text-ink dark:text-ink-dark">{feature.label}</h2>
                                <p className="mt-1 text-xs leading-relaxed text-ink-soft dark:text-ink-soft-dark">{feature.desc}</p>
                            </div>
                        ))}
                    </section>

                    <div className="mx-auto w-full max-w-md rounded-3xl border border-line bg-surface-elev p-8 shadow-[0_1px_3px_rgba(0,0,0,0.04),0_8px_24px_-12px_rgba(0,0,0,0.08)] dark:border-line-dark dark:bg-surface-dark-elev dark:shadow-none">
                        <h2 className="text-2xl font-semibold tracking-tight text-ink dark:text-ink-dark">Selamat datang</h2>
                        <p className="mt-2 text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark">
                            Masuk pakai Strava untuk mulai catat lari kamu
                        </p>

                        <a
                            href={authStravaUrl}
                            className="mt-8 inline-flex w-full items-center justify-center gap-2.5 rounded-xl bg-strava-orange px-5 py-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-strava-orange-hover focus:outline-none focus:ring-4 focus:ring-strava-orange/30"
                        >
                            <svg viewBox="0 0 24 24" className="h-5 w-5" fill="currentColor" aria-hidden>
                                <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7 13.828h4.169" />
                            </svg>
                            Connect with Strava
                        </a>

                        {props.demoLoginEnabled && (
                            <button
                                type="button"
                                onClick={submitDemo}
                                disabled={demoForm.processing}
                                className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-line bg-surface-elev px-5 py-3 text-sm font-semibold text-ink-soft transition hover:bg-surface focus:outline-none focus:ring-4 focus:ring-brand-500/20 dark:border-line-dark dark:bg-surface-dark-elev dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                            >
                                <Icon icon="mdi:play-circle-outline" width={18} height={18} aria-hidden />
                                Coba versi demo
                            </button>
                        )}

                        <p className="mt-5 text-center text-xs leading-relaxed text-ink-soft dark:text-ink-soft-dark">
                            Kami hanya pakai Strava untuk login dan baca aktivitas lari kamu
                        </p>
                    </div>

                    <p className="mt-6 text-center text-xs text-ink-soft dark:text-ink-soft-dark">
                        Made with <span className="text-pop-500">♥</span> by a runner, for runners
                    </p>
                </main>
            </div>
        </AppShell>
    );
}
