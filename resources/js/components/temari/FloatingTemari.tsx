import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import TemariMascot from './TemariMascot';
import type { Mood, SharedProps } from '@/types/inertia';

const POLL_MS = 5000;

const PAGE_TIPS: Record<string, string> = {
    '/': 'Tap aku kalau penasaran sama vibe hari ini ✨',
    '/aktivitas': 'Pilih satu run buat lihat ceritanya 🏃‍♀️',
    '/kartu': 'Tap kartu Epic/Legendaris buat efek konfeti 🎉',
    '/catatan': 'Riwayat mingguan + PR ledger lo ada di sini 📒',
    '/profil': 'Statistik singkat akun Strava 🧾',
    '/pengaturan': 'Atur preferensi & Temari di sini ⚙️',
};

function tipForPath(path: string): string {
    const match = Object.keys(PAGE_TIPS).find((key) => key !== '/' && path.startsWith(key));
    if (match) return PAGE_TIPS[match];
    return PAGE_TIPS['/'];
}

export default function FloatingTemari() {
    const { props, url } = usePage<SharedProps>();
    const [open, setOpen] = useState(false);

    const activity = props.aiActivity ?? { pending: 0, queued: 0, processing: 0 };
    const total = activity.pending + activity.queued + activity.processing;
    const isThinking = total > 0;

    useEffect(() => {
        if (!isThinking) return;
        let timer: ReturnType<typeof setInterval> | null = null;

        const start = () => {
            if (timer !== null || document.hidden) return;
            timer = setInterval(() => {
                router.reload({ only: ['aiActivity'] });
            }, POLL_MS);
        };
        const stop = () => {
            if (timer === null) return;
            clearInterval(timer);
            timer = null;
        };
        const onVisibility = () => {
            if (document.hidden) stop();
            else start();
        };

        start();
        document.addEventListener('visibilitychange', onVisibility);
        return () => {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [isThinking]);

    const mood: Mood = isThinking ? 'spinning' : 'glow';
    const bubbleText = isThinking
        ? `${total} analisis lagi dipikirin Temari…`
        : tipForPath(url);

    return (
        <div
            aria-hidden={false}
            className="pointer-events-none fixed bottom-4 right-4 z-50 hidden md:block"
        >
            <div className="pointer-events-auto flex flex-col items-end gap-2">
                <AnimatePresence>
                    {open && (
                        <motion.div
                            initial={{ opacity: 0, y: 10, scale: 0.95 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 10, scale: 0.95 }}
                            transition={{ duration: 0.18 }}
                            className="max-w-xs rounded-2xl border border-line bg-surface-elev px-4 py-3 text-sm leading-relaxed text-ink shadow-lg"
                            role="status"
                        >
                            <p>{bubbleText}</p>
                            {isThinking && (
                                <div className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] font-medium text-ink-meta">
                                    {activity.pending > 0 && (
                                        <span className="inline-flex items-center gap-1">
                                            <span className="h-1.5 w-1.5 rounded-full bg-ink-meta" aria-hidden />
                                            {activity.pending} nunggu
                                        </span>
                                    )}
                                    {activity.queued > 0 && (
                                        <span className="inline-flex items-center gap-1">
                                            <span className="h-1.5 w-1.5 rounded-full bg-pop-500" aria-hidden />
                                            {activity.queued} antri
                                        </span>
                                    )}
                                    {activity.processing > 0 && (
                                        <span className="inline-flex items-center gap-1">
                                            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-mood-spinning" aria-hidden />
                                            {activity.processing} jalan
                                        </span>
                                    )}
                                </div>
                            )}
                        </motion.div>
                    )}
                </AnimatePresence>

                <button
                    type="button"
                    onClick={() => setOpen((v) => !v)}
                    aria-label={isThinking ? `${total} analisis sedang berjalan` : 'Halo dari Temari'}
                    className="relative rounded-full bg-surface-elev p-1 shadow-lg ring-1 ring-line transition hover:ring-brand-400"
                >
                    <TemariMascot mood={mood} sizeClass="h-14 w-14" idle="breath" />
                    {isThinking && (
                        <span
                            aria-hidden
                            className="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-mood-spinning px-1 text-[10px] font-bold text-white shadow ring-2 ring-surface-elev"
                        >
                            {total}
                        </span>
                    )}
                </button>
            </div>
        </div>
    );
}
