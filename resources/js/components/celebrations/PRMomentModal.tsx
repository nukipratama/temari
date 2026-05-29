import { Link } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import Temari from '@/components/temari/Temari';

interface PrData {
    activityId: number;
    categoryLabel: string;
    timeDisplay: string;
}

interface PRMomentModalProps {
    pr: PrData | null;
    onClose: () => void;
    onShare?: () => void;
}

export default function PRMomentModal({ pr, onClose, onShare }: Readonly<PRMomentModalProps>) {
    return (
        <AnimatePresence>
            {pr !== null && <motion.div
                key="pr-backdrop"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="fixed inset-0 z-50 flex items-end justify-center sm:items-center"
                style={{
                    background: 'rgba(0,0,0,0.55)',
                    backdropFilter: 'blur(4px)',
                }}
                onClick={onClose}
            >
                <motion.div
                    key="pr-panel"
                    initial={{ opacity: 0, y: 24, scale: 0.97 }}
                    animate={{ opacity: 1, y: 0, scale: 1 }}
                    exit={{ opacity: 0, y: 16, scale: 0.97 }}
                    transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
                    onClick={(e) => e.stopPropagation()}
                    className="relative w-full max-w-[390px] overflow-hidden rounded-t-3xl sm:rounded-3xl"
                    style={{
                        background:
                            'linear-gradient(170deg, var(--color-sky-deep) 0%, var(--color-sky) 30%, var(--color-sky-2) 55%, oklch(58% 0.10 38) 88%, var(--color-horizon-deep) 100%)',
                        color: 'var(--color-cream)',
                        padding: '56px 24px 32px',
                        display: 'flex',
                        flexDirection: 'column',
                    }}
                >
                    {/* Glow */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute bottom-[15%] left-1/2 h-80 w-80 -translate-x-1/2 rounded-full"
                        style={{
                            background:
                                'radial-gradient(circle, oklch(82% 0.16 55 / 0.6), transparent 60%)',
                            filter: 'blur(2px)',
                        }}
                    />

                    {/* Close */}
                    <div className="relative mb-8 flex justify-end">
                        <button
                            onClick={onClose}
                            className="font-mono text-lg text-cream/60 hover:text-cream/90 transition-colors"
                            aria-label="Tutup"
                        >
                            ✕
                        </button>
                    </div>

                    {/* Eyebrow + headline */}
                    <div className="relative text-center">
                        <div className="mb-5 font-mono text-[11px] font-bold uppercase tracking-[0.22em] text-horizon">
                            ★ Rekor baru · {pr.categoryLabel}
                        </div>
                        <h2 className="mb-6 font-display text-[30px] leading-[1.05] tracking-[-0.015em] text-cream">
                            Lo baru aja pecahin <em className="italic">PR.</em>
                        </h2>
                    </div>

                    {/* Mascot */}
                    <div className="relative mb-6 flex justify-center">
                        <Temari pose="excited" size={180} />
                    </div>

                    {/* Big time */}
                    <div className="relative mb-5 text-center">
                        <div
                            className="font-sans text-[clamp(72px,20vw,108px)] font-bold leading-[0.85] tracking-[-0.04em] tabular-nums"
                            style={{
                                background:
                                    'linear-gradient(180deg, var(--color-cream), oklch(85% 0.10 50))',
                                WebkitBackgroundClip: 'text',
                                WebkitTextFillColor: 'transparent',
                                backgroundClip: 'text',
                            }}
                        >
                            {pr.timeDisplay}
                        </div>
                    </div>

                    {/* CTAs */}
                    <div className="relative mt-2 flex flex-col gap-2.5">
                        {onShare && (
                            <button
                                onClick={onShare}
                                className="w-full rounded-full bg-horizon py-[14px] font-sans text-sm font-semibold text-sky transition-opacity hover:opacity-90"
                            >
                                Bagikan
                            </button>
                        )}
                        <Link
                            href={`/aktivitas/${pr.activityId}`}
                            onClick={onClose}
                            className="w-full rounded-full border border-cream/30 py-3 text-center font-sans text-[13px] font-medium text-cream transition-colors hover:bg-cream/10"
                        >
                            Lihat detail lari
                        </Link>
                    </div>
                </motion.div>
            </motion.div>}
        </AnimatePresence>
    );
}
