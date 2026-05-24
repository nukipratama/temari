import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ConfettiBurst from '@/components/ConfettiBurst';
import HeroPanel from '@/components/ui/HeroPanel';
import Kartu from './Kartu';
import PillButton from '@/components/ui/PillButton';
import TemariProto, { type TemariPose } from '@/components/temari/TemariProto';
import TemariMascot from '@/components/temari/TemariMascot';
import { RARITY_LABELS } from '@/lib/runcard';
import { formatDuration, formatKm, formatPace, paceSecPerKm } from '@/lib/pace';
import { csrfToken } from '@/lib/http';
import type { PendingReveal, Rarity } from '@/types/inertia';

interface CardRevealProps {
    pending: PendingReveal;
}

interface Frame {
    pose: TemariPose;
    /** When true, render the full-body running mascot instead of the bunny.
     *  Reserved for the "Sync masuk" frame — Temari literally sprinting back
     *  from Strava with your run. Every other frame stays on the bunny so
     *  identity is consistent across reveal/voice/hero surfaces. */
    runner?: boolean;
    eyebrow: string;
    title: string;
    subtitle?: string;
    showKartu?: boolean;
    showConfetti?: boolean;
}

const THEATRICAL_RARITIES: ReadonlyArray<Rarity> = ['rare', 'epic', 'legendary'];

function framesFor(theatrical: boolean, rarity: Rarity, name: string): Frame[] {
    const rarityLabel = RARITY_LABELS[rarity];
    if (!theatrical) {
        // Intimate flow: 2 frames for common/uncommon
        return [
            {
                pose: 'reading',
                runner: true,
                eyebrow: 'Sync masuk',
                title: 'Aku lagi baca lari kamu…',
            },
            {
                pose: 'holding',
                eyebrow: `Kartu baru · ${rarityLabel}`,
                title: name,
                subtitle: 'Udah masuk koleksimu.',
                showKartu: true,
            },
        ];
    }

    // Theatrical flow: 4 frames for rare+
    return [
        {
            pose: 'reading',
            runner: true,
            eyebrow: 'Sync masuk',
            title: 'Aku lagi baca lari kamu…',
        },
        {
            pose: 'excited',
            eyebrow: 'Verdict',
            title: 'Ini layak kartu.',
        },
        {
            pose: 'holding',
            eyebrow: `★ ${rarityLabel}`,
            title: name,
            showKartu: true,
            showConfetti: true,
        },
        {
            pose: 'proud',
            eyebrow: 'Disimpan',
            title: 'Udah masuk koleksimu.',
            subtitle: 'Tarik napas, lalu balik lagi ke larimu.',
            showKartu: true,
        },
    ];
}

export default function CardReveal({ pending }: Readonly<CardRevealProps>) {
    const theatrical = THEATRICAL_RARITIES.includes(pending.rarity);
    const frames = useMemo(
        () => framesFor(theatrical, pending.rarity, pending.special_move),
        [theatrical, pending.rarity, pending.special_move],
    );

    const [step, setStep] = useState(0);
    const [confettiKey, setConfettiKey] = useState<string | null>(null);
    const sentRef = useRef(false);

    // /api/kartu/{card}/seen returns plain JSON, so Inertia's router can't
    // call it (it errors on non-Inertia responses). Fire-and-forget; the
    // modal dismissal doesn't gate on the POST landing.
    const markSeen = useCallback((): void => {
        if (sentRef.current) return;
        sentRef.current = true;
        void fetch(`/api/kartu/${pending.card_id}/seen`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{}',
        }).catch(() => { /* silent — next reload picks up server state */ });
    }, [pending.card_id]);

    const dismiss = useCallback((): void => {
        markSeen();
        router.reload({ only: ['pendingReveal'] });
    }, [markSeen]);

    const viewKoleksi = useCallback((): void => {
        markSeen();
        router.visit('/kartu', { preserveScroll: false });
    }, [markSeen]);

    const advance = useCallback(() => {
        setStep((s) => {
            if (s + 1 >= frames.length) {
                return s;
            }
            return s + 1;
        });
    }, [frames.length]);

    useEffect(() => {
        if (frames[step]?.showConfetti) {
            setConfettiKey(`reveal-${pending.card_id}-${step}`);
        }
    }, [step, frames, pending.card_id]);

    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                dismiss();
            }
            if (e.key === ' ' || e.key === 'Enter' || e.key === 'ArrowRight') {
                e.preventDefault();
                advance();
            }
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [advance, dismiss]);

    const frame = frames[step] ?? frames[frames.length - 1];
    const isLastFrame = step === frames.length - 1;
    const km = formatKm(pending.distance_m);
    const durasi = pending.moving_time_sec != null ? formatDuration(pending.moving_time_sec) : '—';
    const trimp = pending.trimp_edwards != null ? Math.round(pending.trimp_edwards).toString() : '—';
    const subtitle = buildSubtitle(pending);
    const onBackdrop = isLastFrame ? dismiss : advance;
    const onPrimary = isLastFrame ? viewKoleksi : advance;

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label="Kartu baru"
            className="fixed inset-0 z-50 flex items-center justify-center bg-sky-deep/80 px-4 backdrop-blur-md"
            onClick={onBackdrop}
        >
            <ConfettiBurst burstKey={confettiKey} />
            <motion.div
                key={step}
                initial={{ opacity: 0, scale: 0.96, y: 12 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                transition={{ duration: 0.35, ease: [0.22, 1, 0.36, 1] }}
                onClick={(e) => e.stopPropagation()}
                className="w-full max-w-3xl"
            >
                    <HeroPanel className="px-8 py-10 sm:px-12 sm:py-12">
                        <div className="grid items-center gap-9 lg:grid-cols-[200px_1fr]">
                            <div className="flex justify-center">
                                {frame.runner ? (
                                    <TemariMascot mood="nyala" sizeClass="h-[200px] w-[200px]" idle="mood" />
                                ) : (
                                    <TemariProto pose={frame.pose} size={200} />
                                )}
                            </div>
                            <div>
                                <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                                    {frame.eyebrow}
                                </div>
                                <h2 className="font-display text-display-sm text-cream">
                                    <em className="italic text-horizon">{frame.title}</em>
                                </h2>
                                {frame.subtitle && (
                                    <p className="mt-4 font-display text-base italic leading-relaxed text-cream/80 sm:text-lg">
                                        {frame.subtitle}
                                    </p>
                                )}
                                {frame.showKartu && (
                                    <motion.div
                                        initial={{ opacity: 0, rotate: -6, y: 12 }}
                                        animate={{ opacity: 1, rotate: -3, y: 0 }}
                                        transition={{ duration: 0.6, delay: 0.15 }}
                                        className="mt-6 max-w-md"
                                    >
                                        <Kartu
                                            name={pending.special_move}
                                            subtitle={subtitle}
                                            km={km}
                                            durasi={durasi}
                                            trimp={trimp}
                                            rarity={pending.rarity}
                                            tags={(pending.badges ?? []).slice(0, 2).map(prettyBadge)}
                                            size="md"
                                            onSky
                                        />
                                    </motion.div>
                                )}
                                <div className="mt-7 flex flex-wrap items-center gap-2.5">
                                    <PillButton tone="horizon" onClick={onPrimary}>
                                        {isLastFrame ? 'Lihat koleksi' : 'Lanjut'}
                                    </PillButton>
                                    <button
                                        type="button"
                                        onClick={dismiss}
                                        className="font-mono text-[11px] font-semibold uppercase tracking-[0.14em] text-cream/60 hover:text-cream"
                                    >
                                        {isLastFrame ? 'Tutup' : 'Skip'}
                                    </button>
                                </div>
                                <div className="mt-4 font-mono text-[9px] uppercase tracking-[0.14em] text-cream/40">
                                    Frame {step + 1} / {frames.length} · tap untuk lanjut
                                </div>
                            </div>
                        </div>
                    </HeroPanel>
            </motion.div>
        </div>
    );
}

function prettyBadge(slug: string): string {
    return slug
        .split('_')
        .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
        .join(' ');
}

function buildSubtitle(pending: PendingReveal): string | null {
    if (pending.detail_name === null) return null;
    const paceSec = paceSecPerKm(pending.moving_time_sec, pending.distance_m);
    if (paceSec === null) return pending.detail_name;
    return `${pending.detail_name} · ${formatPace(paceSec)}/km`;
}
