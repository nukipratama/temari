import { AnimatePresence, motion } from 'framer-motion';
import { useRef, useState } from 'react';
import { toPng } from 'html-to-image';
import { Icon } from '@iconify/react';
import BrandMark from '@/components/BrandMark';
import Kartu from './Kartu';
import { cn } from '@/lib/cn';
import { RARITY_LABELS } from '@/lib/runcard';
import type { Rarity } from '@/types/inertia';

export interface ShareKartuData {
    id: number;
    name: string;
    rarity: Rarity;
    subtitle: string | null;
    date: string | null;
    km: string;
    durasi: string;
    trimp: string;
    hr: string | null;
    location: string | null;
    weather: string | null;
    tags: string[];
    quote: string | null;
}

interface ShareIgModalProps {
    kartu: ShareKartuData | null;
    onClose: () => void;
}

type Theme = 'Dawn' | 'Sky' | 'Cream' | 'Inverted';
type Format = 'story' | 'feed';

const THEME_BG: Record<Theme, React.CSSProperties> = {
    Dawn: {
        background:
            'linear-gradient(170deg, var(--color-sky-deep) 0%, var(--color-sky) 50%, oklch(58% 0.10 38) 88%, var(--color-horizon-deep) 100%)',
        color: 'var(--color-cream)',
    },
    Sky: { background: 'var(--color-sky)', color: 'var(--color-cream)' },
    Cream: { background: 'var(--color-cream-deep)', color: 'var(--color-ink)' },
    Inverted: { background: 'var(--color-sky-deep)', color: 'var(--color-cream)' },
};

const THEMES: Theme[] = ['Dawn', 'Sky', 'Cream', 'Inverted'];

export default function ShareIgModal({ kartu, onClose }: Readonly<ShareIgModalProps>) {
    const [theme, setTheme] = useState<Theme>('Dawn');
    const [showStats, setShowStats] = useState(true);
    const [showQuote, setShowQuote] = useState(true);
    const [format, setFormat] = useState<Format>('story');
    const previewRef = useRef<HTMLDivElement>(null);

    if (kartu === null) return null;

    const isDark = theme !== 'Cream';
    const dividerColor = isDark ? 'rgba(246,241,232,0.15)' : 'rgba(31,39,71,0.10)';
    const metaColor = isDark ? 'rgba(246,241,232,0.55)' : 'var(--color-ink-3)';

    const statItems = [
        { v: kartu.km, l: 'KM' },
        { v: kartu.durasi, l: 'Durasi' },
        { v: kartu.trimp, l: 'TRIMP' },
        ...(kartu.hr ? [{ v: kartu.hr, l: 'HR' }] : []),
        ...(kartu.weather ? [{ v: kartu.weather, l: 'Cuaca' }] : []),
        ...(kartu.location ? [{ v: kartu.location, l: 'Lokasi' }] : []),
    ];

    const captureImage = async (): Promise<Blob> => {
        const dataUrl = await toPng(previewRef.current!, {
            pixelRatio: 3,
            skipFonts: true,   // avoids CORS errors from Google Fonts + cross-origin CSS
        });
        const res = await fetch(dataUrl);
        return res.blob();
    };

    const handleShare = async () => {
        if (previewRef.current && typeof navigator.share === 'function') {
            try {
                const blob = await captureImage();
                const file = new File([blob], `${kartu.name}.png`, { type: 'image/png' });
                if (navigator.canShare?.({ files: [file] })) {
                    await navigator.share({ files: [file], title: `${kartu.name} · TemanLari` });
                    return;
                }
            } catch {
                // fall through to URL share
            }
        }
        const url = `${globalThis.location.origin}/kartu/${kartu.id}`;
        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({
                    title: `${kartu.name} · TemanLari`,
                    text: kartu.quote ?? `Kartu ${RARITY_LABELS[kartu.rarity]}: ${kartu.name}`,
                    url,
                });
            } catch {
                // user cancelled or API unavailable
            }
        } else {
            await navigator.clipboard.writeText(url).catch(() => { /* silent */ });
        }
    };

    const handleCopy = async () => {
        if (!previewRef.current) return;
        try {
            const blob = await captureImage();
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        } catch {
            // clipboard API unavailable — silent
        }
    };

    return (
        <AnimatePresence>
            <motion.div
                key="share-backdrop"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="fixed inset-0 z-[51] flex items-center justify-center p-4"
                style={{ background: 'rgba(0,0,0,0.5)', backdropFilter: 'blur(6px)' }}
                onClick={onClose}
            >
                <motion.div
                    key="share-panel"
                    initial={{ opacity: 0, scale: 0.96, y: 8 }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    exit={{ opacity: 0, scale: 0.96, y: 8 }}
                    transition={{ duration: 0.3, ease: [0.22, 1, 0.36, 1] }}
                    onClick={(e) => e.stopPropagation()}
                    className="flex w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-cream shadow-2xl lg:max-w-4xl lg:flex-row"
                    style={{ maxHeight: '92dvh' }}
                >
                    {/* ── LEFT: preview ── */}
                    <div className="flex flex-col items-center gap-3 overflow-y-auto bg-cream-deep p-5 lg:w-80 lg:shrink-0 lg:overflow-hidden">
                        {/* Preview canvas */}
                        <div
                            ref={previewRef}
                            className={cn(
                                'relative w-full overflow-hidden rounded-2xl',
                                format === 'story' ? 'aspect-[9/16]' : 'aspect-square',
                            )}
                            style={{
                                ...THEME_BG[theme],
                                display: 'flex',
                                flexDirection: 'column',
                                padding: '1.25rem',
                                boxShadow: '0 16px 48px rgba(31,39,71,0.25)',
                            }}
                        >
                            {/* Glow */}
                            <span
                                aria-hidden
                                className="pointer-events-none absolute bottom-[12%] left-1/2 h-48 w-48 -translate-x-1/2 rounded-full"
                                style={{
                                    background: 'radial-gradient(circle, oklch(82% 0.14 55 / 0.45), transparent 60%)',
                                    filter: 'blur(8px)',
                                }}
                            />

                            {/* BrandMark — absolutely anchored top-right, always visible */}
                            <div className="absolute right-4 top-4 scale-[0.55] origin-top-right">
                                <BrandMark tone={isDark ? 'cream' : 'ink'} />
                            </div>

                            {/* Rarity */}
                            <div
                                className="relative mb-2 whitespace-nowrap font-mono text-[10px] font-bold uppercase tracking-[0.16em]"
                                style={{ color: 'var(--color-horizon)' }}
                            >
                                ★ Kartu {RARITY_LABELS[kartu.rarity]}
                            </div>

                            {/* Name */}
                            <h3
                                className={cn(
                                    'relative mb-2 font-display leading-[0.95] tracking-[-0.025em]',
                                    format === 'story' ? 'text-2xl' : 'text-xl',
                                )}
                                style={{ color: isDark ? 'var(--color-horizon)' : 'var(--color-ink)' }}
                            >
                                <em className="italic">{kartu.name}.</em>
                            </h3>

                            {/* Card */}
                            <div className="relative flex flex-1 items-center justify-center">
                                <div
                                    className={cn(
                                        'w-full drop-shadow-xl',
                                        format === 'story' ? '-rotate-[3deg]' : '-rotate-[2deg]',
                                    )}
                                >
                                    <Kartu
                                        name={kartu.name}
                                        subtitle={kartu.date ?? undefined}
                                        km={kartu.km}
                                        durasi={kartu.durasi}
                                        trimp={kartu.trimp}
                                        rarity={kartu.rarity}
                                        tags={kartu.tags.slice(0, 1)}
                                        size="md"
                                        onSky={isDark}
                                        className="w-full"
                                    />
                                </div>
                            </div>

                            {/* Quote — story only */}
                            {format === 'story' && showQuote && kartu.quote && (
                                <p
                                    className="relative mt-2 text-center font-display text-xs italic leading-snug"
                                    style={{ color: isDark ? 'rgba(246,241,232,0.8)' : 'var(--color-ink-2)' }}
                                >
                                    &ldquo;{kartu.quote}&rdquo;
                                </p>
                            )}

                            {/* Stats — story only */}
                            {format === 'story' && showStats && (
                                <div
                                    className="relative mt-3 grid gap-x-1 gap-y-2 border-t pt-3"
                                    style={{
                                        borderColor: dividerColor,
                                        gridTemplateColumns: `repeat(${Math.min(statItems.length, 3)}, 1fr)`,
                                    }}
                                >
                                    {statItems.map(({ v, l }) => (
                                        <div key={l} className="min-w-0 text-center">
                                            <div className="truncate font-sans text-xs font-bold tabular-nums leading-none">
                                                {v}
                                            </div>
                                            <div
                                                className="mt-0.5 font-mono text-[7px] uppercase tracking-[0.12em]"
                                                style={{ color: metaColor }}
                                            >
                                                {l}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Format picker */}
                        <div className="grid w-full grid-cols-2 gap-2">
                            {(['story', 'feed'] as Format[]).map((f) => (
                                <button
                                    key={f}
                                    onClick={() => setFormat(f)}
                                    className={cn(
                                        'rounded-xl p-3 text-xs font-medium transition',
                                        format === f
                                            ? 'border-2 border-horizon bg-cream font-semibold text-ink'
                                            : 'border-2 border-transparent bg-cream text-ink-2 hover:border-cream-deep',
                                    )}
                                >
                                    <div
                                        className={cn(
                                            'mx-auto mb-1.5 rounded-sm bg-sky/25',
                                            f === 'story' ? 'h-7 w-4' : 'h-6 w-6',
                                        )}
                                    />
                                    {f === 'story' ? 'Story · 9:16' : 'Feed · 1:1'}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* ── RIGHT: controls ── */}
                    <div className="flex flex-1 flex-col overflow-y-auto">
                        {/* Header */}
                        <div className="flex items-center gap-3 border-b border-cream-deep px-5 pb-3.5 pt-5">
                            <button
                                onClick={onClose}
                                aria-label="Tutup"
                                className="flex h-7 w-7 items-center justify-center rounded-full text-ink-3 transition hover:bg-cream-deep hover:text-ink"
                            >
                                <Icon icon="mdi:close" width={16} height={16} />
                            </button>
                            <div className="flex-1 text-center">
                                <div className="font-mono text-[9px] uppercase tracking-[0.14em] text-ink-3">
                                    Bagikan kartu
                                </div>
                                <div className="font-display text-xl tracking-tight text-ink">
                                    {kartu.name}
                                </div>
                            </div>
                            <div className="w-7" />
                        </div>

                        <div className="flex flex-1 flex-col gap-5 px-5 pb-6 pt-5">
                            {/* Theme */}
                            <div>
                                <div className="mb-2 font-mono text-[11px] font-bold uppercase tracking-[0.12em] text-ink-3">
                                    Tema
                                </div>
                                <div className="flex gap-2">
                                    {THEMES.map((t) => (
                                        <button
                                            key={t}
                                            onClick={() => setTheme(t)}
                                            className={cn(
                                                'flex-1 rounded-full py-2 text-sm font-medium transition',
                                                theme === t
                                                    ? 'bg-sky font-semibold text-cream'
                                                    : 'bg-cream-deep text-ink-2 hover:bg-sky/10',
                                            )}
                                        >
                                            {t}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Toggles — disabled in feed format */}
                            <div className={cn('overflow-hidden rounded-xl bg-cream-deep', format === 'feed' && 'pointer-events-none opacity-40')}>
                                {[
                                    { label: 'Tampilkan data', value: showStats, toggle: () => setShowStats((v) => !v) },
                                    { label: 'Tampilkan quote', value: showQuote, toggle: () => setShowQuote((v) => !v) },
                                ].map(({ label, value, toggle }, i) => (
                                    <div
                                        key={label}
                                        className={cn(
                                            'flex items-center justify-between px-4 py-3.5',
                                            i > 0 && 'border-t border-cream',
                                        )}
                                    >
                                        <span className="text-sm text-ink">{label}</span>
                                        <button
                                            onClick={toggle}
                                            aria-checked={value}
                                            role="switch"
                                            className="relative h-5 w-9 shrink-0 rounded-full transition-colors"
                                            style={{ background: value ? 'var(--color-horizon)' : 'rgba(31,39,71,0.15)' }}
                                        >
                                            <span
                                                className="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all"
                                                style={{ left: value ? '18px' : '2px' }}
                                            />
                                        </button>
                                    </div>
                                ))}
                            </div>

                            <div className="flex-1" />

                            {/* CTAs */}
                            <div className="flex flex-col gap-2">
                                <button
                                    onClick={handleShare}
                                    className="w-full rounded-full bg-horizon-deep py-3.5 font-sans text-sm font-semibold text-white transition-opacity hover:opacity-90"
                                >
                                    Bagikan
                                </button>
                                <button
                                    onClick={handleCopy}
                                    className="w-full rounded-full border border-cream-deep py-3 font-sans text-[13px] font-medium text-ink-2 transition-colors hover:bg-cream-deep"
                                >
                                    Salin Gambar
                                </button>
                            </div>
                        </div>
                    </div>
                </motion.div>
            </motion.div>
        </AnimatePresence>
    );
}
