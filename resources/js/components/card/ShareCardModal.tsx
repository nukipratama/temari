import { AnimatePresence, motion } from 'framer-motion';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { useDismissable } from '@/hooks/useDismissable';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import { kartuUrl } from '@/lib/routes';
import PillButton from '@/components/ui/PillButton';
import { iconButtonVariants, toggleButtonVariants } from '@/lib/variants';
import { RARITY_LABELS } from '@/lib/runcard';
import { MOOD_TO_POSE } from '@/lib/temariPose';
import { drawShareCard, shareCardBlob, type Format, type Layout, type ShareKartuData } from '@/lib/shareCard';
import TemariProto, { type TemariEquipped } from '@/components/temari/TemariProto';
import { serverToEquipped } from '@/lib/equippedAccessories';
import type { SharedProps } from '@/types/inertia';

export type { ShareKartuData };

interface ShareCardModalProps {
    kartu: ShareKartuData | null;
    onClose: () => void;
}

const LAYOUTS: Layout[] = ['kartu', 'rute'];
const LAYOUT_LABELS: Record<Layout, string> = {
    kartu: 'Kartu',
    rute: 'Rute',
};

export default function ShareCardModal({ kartu, onClose }: Readonly<ShareCardModalProps>) {
    const [layout, setLayout] = useState<Layout>('kartu');
    const [format, setFormat] = useState<Format>('story');
    // Transient status under the CTAs: confirms a copy/share that has no native
    // UI of its own, or surfaces a failure instead of swallowing it silently.
    const [status, setStatus] = useState<{ tone: 'ok' | 'err'; text: string } | null>(null);
    const [temariImg, setTemariImg] = useState<HTMLImageElement | null>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const temariContainerRef = useRef<HTMLDivElement>(null);

    // The mascot is dressed exactly as the user has it elsewhere. Read the
    // shared equip state defensively (mirrors Temari component) so this still renders
    // a bare bunny when there's no Inertia page context (e.g. unit tests).
    let equipped: TemariEquipped | null = null;
    try {
        const acc = usePage<SharedProps>().props.equippedAccessories;
        if (acc) {
            equipped = serverToEquipped(acc);
        }
    } catch {
        equipped = null;
    }

    useDismissable(kartu !== null, panelRef, onClose);
    useFocusTrap(kartu !== null, panelRef);

    // Serialise the live Temari SVG (with real accessories from the DOM) into a
    // canvas-compatible image whenever the mood changes. Falls back gracefully
    // when the element isn't ready.
    useEffect(() => {
        if (kartu === null) return;
        const container = temariContainerRef.current;
        if (!container) return;

        // Give React a tick to render the Temari into the hidden container.
        const id = globalThis.setTimeout(() => {
            const svg = container.querySelector('svg');
            if (!svg) return;
            try {
                const svgStr = new XMLSerializer().serializeToString(svg);
                const url = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svgStr)}`;
                const img = new Image();
                img.onload = () => setTemariImg(img);
                img.src = url;
            } catch {
                // Serialisation failure is non-fatal — the flat bunny fallback kicks in.
            }
        }, 60);
        return () => globalThis.clearTimeout(id);
    }, [kartu?.mood, kartu]);

    // Repaint the fixed-resolution canvas whenever any knob changes. The canvas
    // IS the export, so the on-screen preview can never drift from the shared
    // image, and the output is identical on every device.
    useEffect(() => {
        if (kartu === null || canvasRef.current === null) {
            return;
        }
        void drawShareCard(canvasRef.current, { kartu, layout, format, temariImg });
    }, [kartu, layout, format, temariImg]);

    // Auto-clear the status line so it reads as a transient toast.
    useEffect(() => {
        if (status === null) return;
        const id = globalThis.setTimeout(() => setStatus(null), 2600);
        return () => globalThis.clearTimeout(id);
    }, [status]);

    if (kartu === null) return null;

    // The route-hero template needs a polyline; hide it for no-GPS runs.
    const hasRoute = kartu.polyline != null && kartu.polyline !== '';
    const availableLayouts = hasRoute ? LAYOUTS : LAYOUTS.filter((l) => l !== 'rute');

    const cfg = { kartu, layout, format, temariImg };

    const captureImage = (): Promise<Blob> => shareCardBlob(cfg);

    const handleShare = async () => {
        if (typeof navigator.share === 'function') {
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
        const url = `${globalThis.location.origin}${kartuUrl(kartu)}`;
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
        } else if (navigator.clipboard?.writeText !== undefined) {
            try {
                await navigator.clipboard.writeText(url);
                setStatus({ tone: 'ok', text: 'Link kartu kesalin.' });
            } catch {
                setStatus({ tone: 'err', text: 'Gagal nyalin link.' });
            }
        } else {
            setStatus({ tone: 'err', text: 'Browser ini belum dukung berbagi.' });
        }
    };

    const handleCopy = async () => {
        if (typeof ClipboardItem === 'undefined' || navigator.clipboard?.write === undefined) {
            setStatus({ tone: 'err', text: 'Browser ini belum dukung salin gambar. Pakai Bagikan ya.' });
            return;
        }
        try {
            const blob = await captureImage();
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
            setStatus({ tone: 'ok', text: 'Gambar kartu kesalin.' });
        } catch {
            setStatus({ tone: 'err', text: 'Gagal nyalin gambar. Coba Bagikan aja.' });
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
            >
                <motion.div
                    key="share-panel"
                    ref={panelRef}
                    role="dialog"
                    aria-modal="true"
                    initial={{ opacity: 0, scale: 0.96, y: 8 }}
                    animate={{ opacity: 1, scale: 1, y: 0 }}
                    exit={{ opacity: 0, scale: 0.96, y: 8 }}
                    transition={{ duration: 0.3, ease: [0.22, 1, 0.36, 1] }}
                    className="flex w-full max-w-md flex-col overflow-hidden rounded-3xl bg-cream shadow-2xl"
                    style={{ maxHeight: '92dvh' }}
                >
                    {/* Header — pinned. */}
                    <div className="flex items-center gap-3 border-b border-cream-deep px-5 py-3.5">
                        <button
                            type="button"
                            onClick={onClose}
                            aria-label="Tutup"
                            className={iconButtonVariants({ size: 'sm' })}
                        >
                            <Icon icon="mdi:close" width={16} height={16} />
                        </button>
                        <div className="flex-1 text-center">
                            <div className="font-mono font-bold text-[11px] uppercase tracking-[0.14em] text-ink-2">
                                Bagikan kartu
                            </div>
                            <div className="font-display text-xl tracking-tight text-ink">
                                {kartu.name}
                            </div>
                        </div>
                        <div className="w-8" />
                    </div>

                    {/* Body — preview + pickers, scrolls on short screens. */}
                    <div className="flex flex-1 flex-col items-center gap-4 overflow-y-auto bg-cream-deep px-5 py-5">
                        {/* Preview canvas — fixed internal resolution, bounded by HEIGHT so
                            a tall 9:16 story scales to fit instead of being forced to the
                            column width. Width derives from the canvas's intrinsic ratio, so
                            the bitmap is never distorted. This canvas IS the exported image. */}
                        <canvas
                            ref={canvasRef}
                            width={1080}
                            height={format === 'story' ? 1920 : 1080}
                            aria-label={`Pratinjau kartu ${kartu.name}`}
                            className="block rounded-2xl"
                            style={{ maxWidth: '100%', maxHeight: '52vh', boxShadow: '0 16px 48px rgba(31,39,71,0.25)' }}
                        />

                        {/* Format picker */}
                        <div className="grid w-full grid-cols-2 gap-2">
                            {(['story', 'feed'] as Format[]).map((f) => (
                                <button
                                    key={f}
                                    type="button"
                                    onClick={() => setFormat(f)}
                                    aria-pressed={format === f}
                                    className={cn(
                                        'focus-ring flex items-center justify-center gap-2 rounded-xl p-2.5 text-xs font-medium transition',
                                        format === f
                                            ? 'border-2 border-sky bg-cream font-semibold text-ink'
                                            : 'border-2 border-transparent bg-cream text-ink-2 hover:border-cream-deep',
                                    )}
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'rounded-sm bg-sky/25',
                                            f === 'story' ? 'h-6 w-3.5' : 'h-5 w-5',
                                        )}
                                    />
                                    {f === 'story' ? 'Potret · 9:16' : 'Persegi · 1:1'}
                                </button>
                            ))}
                        </div>

                        {/* Gaya — template picker. Hidden when a no-GPS run leaves only
                            the Kartu layout, so there's nothing to choose. */}
                        {availableLayouts.length > 1 && (
                            <div className="flex w-full gap-2">
                                {availableLayouts.map((l) => (
                                    <button
                                        key={l}
                                        type="button"
                                        onClick={() => setLayout(l)}
                                        aria-pressed={layout === l}
                                        className={cn(toggleButtonVariants({ selected: layout === l, size: 'md' }), 'flex-1')}
                                    >
                                        {LAYOUT_LABELS[l]}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* CTAs — pinned footer. */}
                    <div className="flex flex-col gap-2 border-t border-cream-deep bg-cream px-5 py-4">
                        <PillButton tone="sky" onClick={handleShare} className="w-full justify-center py-3.5 font-semibold">
                            Bagikan
                        </PillButton>
                        <PillButton tone="ghost" onClick={handleCopy} className="w-full justify-center">
                            Salin gambar
                        </PillButton>
                        {status !== null && (
                            <p
                                role="status"
                                aria-live="polite"
                                className={cn(
                                    'text-center font-sans text-xs',
                                    status.tone === 'ok' ? 'text-leaf-deep' : 'text-ember-deep',
                                )}
                            >
                                {status.text}
                            </p>
                        )}
                    </div>
                </motion.div>
            </motion.div>
            {/* Hidden container — TemariProto renders its SVG here with rarity-driven
                headband/pose so we can serialise it to a canvas image. */}
            <div ref={temariContainerRef} aria-hidden className="sr-only pointer-events-none">
                <TemariProto
                    pose={MOOD_TO_POSE[kartu.mood]}
                    equipped={equipped}
                    size={120}
                    animate={false}
                />
            </div>
        </AnimatePresence>
    );
}
