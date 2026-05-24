import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { useCallback, useEffect, useMemo, useState } from 'react';
import ConfettiBurst from '@/components/ConfettiBurst';
import HeroPanel from '@/components/ui/HeroPanel';
import Kartu from './Kartu';
import PillButton from '@/components/ui/PillButton';
import TemariProto, { type TemariPose } from '@/components/temari/TemariProto';
import { RARITY_LABELS } from '@/lib/runcard';
import type { PendingReveal, Rarity } from '@/types/inertia';

interface CardRevealProps {
    pending: PendingReveal;
}

interface Frame {
    pose: TemariPose;
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

    const finish = useCallback(() => {
        router.post(
            `/api/kartu/${pending.card_id}/seen`,
            {},
            {
                preserveScroll: true,
                preserveState: false,
                only: ['pendingReveal'],
            },
        );
    }, [pending.card_id]);

    const advance = useCallback(() => {
        setStep((s) => {
            if (s + 1 >= frames.length) {
                finish();
                return s;
            }
            return s + 1;
        });
    }, [frames.length, finish]);

    useEffect(() => {
        if (frames[step]?.showConfetti) {
            setConfettiKey(`reveal-${pending.card_id}-${step}`);
        }
    }, [step, frames, pending.card_id]);

    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                finish();
            }
            if (e.key === ' ' || e.key === 'Enter' || e.key === 'ArrowRight') {
                e.preventDefault();
                advance();
            }
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [advance, finish]);

    const frame = frames[step] ?? frames[frames.length - 1];
    const isLastFrame = step === frames.length - 1;

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label="Kartu baru"
            className="fixed inset-0 z-50 flex items-center justify-center bg-sky-deep/80 px-4 backdrop-blur-md"
            onClick={advance}
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
                                <TemariProto pose={frame.pose} size={200} />
                            </div>
                            <div>
                                <div className="mb-3 font-mono text-[11px] font-bold uppercase tracking-[0.18em] text-horizon">
                                    {frame.eyebrow}
                                </div>
                                <h2 className="font-display text-[36px] leading-[0.95] tracking-[-0.015em] text-cream sm:text-[52px]">
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
                                            subtitle={pending.detail_name ?? null}
                                            km={'—'}
                                            durasi={'—'}
                                            trimp={'—'}
                                            rarity={pending.rarity}
                                            tags={(pending.badges ?? []).slice(0, 2).map(prettyBadge)}
                                            size="md"
                                            onSky
                                        />
                                    </motion.div>
                                )}
                                <div className="mt-7 flex flex-wrap items-center gap-2.5">
                                    <PillButton tone="horizon" onClick={advance}>
                                        {isLastFrame ? 'Lihat koleksi' : 'Lanjut'}
                                    </PillButton>
                                    <button
                                        type="button"
                                        onClick={finish}
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
