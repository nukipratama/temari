import { Head, router, usePage } from '@inertiajs/react';
import { Icon } from '@iconify/react';
import { motion } from 'framer-motion';
import AppShell from '@/layouts/AppShell';
import PageHero from '@/components/PageHero';
import { fadeInUp } from '@/lib/motion';
import type { SharedProps } from '@/types/inertia';

export default function Settings() {
    const { demoLoginEnabled } = usePage<SharedProps>().props;

    const onLogout = () => router.post('/logout');

    return (
        <AppShell>
            <Head title="Pengaturan" />
            <motion.main
                variants={fadeInUp}
                initial="hidden"
                animate="visible"
                className="w-full px-4 py-6 sm:px-6 sm:py-10"
            >
                <PageHero
                    icon="mdi:cog-outline"
                    title="Pengaturan"
                    subtitle="Atur akun dan preferensi aku supaya nyaman dipakai."
                    className="mb-6"
                />

                <section className="rounded-2xl border border-line bg-surface-elev p-4 dark:border-line-dark dark:bg-surface-dark-elev sm:p-6">
                    <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                        Akun
                    </h2>
                    <p className="mt-3 text-sm leading-relaxed text-ink dark:text-ink-dark">
                        Keluar akan menghapus sesi login di browser ini.
                    </p>
                    <button
                        type="button"
                        onClick={onLogout}
                        className="mt-4 inline-flex items-center gap-2 rounded-xl border border-line bg-surface px-4 py-2 text-sm font-semibold text-ink transition hover:border-mood-cooked hover:text-mood-cooked focus:outline-none focus:ring-4 focus:ring-mood-cooked/20 dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"
                    >
                        <Icon icon="mdi:logout" width={16} height={16} aria-hidden />
                        Keluar
                    </button>
                </section>

                {demoLoginEnabled && (
                    <section className="mt-6 rounded-2xl border border-dashed border-line bg-surface-elev/60 p-4 dark:border-line-dark dark:bg-surface-dark-elev/60 sm:p-6">
                        <h2 className="text-xs font-semibold uppercase tracking-wider text-ink-meta dark:text-ink-meta-dark">
                            Demo Mode
                        </h2>
                        <p className="mt-3 text-sm leading-relaxed text-ink dark:text-ink-dark">
                            Build ini punya tombol &ldquo;Coba versi demo&rdquo; di layar login. Demo user dibuat ulang tiap login dengan
                            data Strava sintetis &mdash; bukan untuk pemakaian sehari-hari.
                        </p>
                    </section>
                )}

                <p className="mt-8 text-sm text-ink-meta dark:text-ink-meta-dark">
                    Pengaturan tambahan akan muncul di sini.
                </p>
            </motion.main>
        </AppShell>
    );
}
