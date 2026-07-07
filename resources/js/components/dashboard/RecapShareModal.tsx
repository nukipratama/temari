import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@iconify/react';
import { cn } from '@/lib/cn';
import { useDismissable } from '@/hooks/useDismissable';
import { useFocusTrap } from '@/hooks/useFocusTrap';
import PillButton from '@/components/ui/PillButton';
import { iconButtonVariants } from '@/lib/variants';
import { drawRecapShare, recapShareBlob, type RecapFormat, type RecapShareData } from '@/lib/recapShare';

interface RecapShareModalProps {
    recap: RecapShareData | null;
    onClose: () => void;
}

export default function RecapShareModal({ recap, onClose }: Readonly<RecapShareModalProps>) {
    const [format, setFormat] = useState<RecapFormat>('story');
    // Transient status under the CTAs: confirms a copy/download that has no
    // native UI of its own, or surfaces a failure instead of swallowing it.
    const [status, setStatus] = useState<{ tone: 'ok' | 'err'; text: string } | null>(null);
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    useDismissable(recap !== null, panelRef, onClose);
    useFocusTrap(recap !== null, panelRef);

    // Repaint the fixed-resolution canvas whenever the recap or format changes.
    // The canvas IS the export, so the on-screen preview can never drift.
    useEffect(() => {
        if (recap === null || canvasRef.current === null) {
            return;
        }
        void drawRecapShare(canvasRef.current, recap, format);
    }, [recap, format]);

    // Auto-clear the status line so it reads as a transient toast.
    useEffect(() => {
        if (status === null) {
            return;
        }
        const id = globalThis.setTimeout(() => setStatus(null), 2600);
        return () => globalThis.clearTimeout(id);
    }, [status]);

    if (recap === null) {
        return null;
    }

    const captureImage = (): Promise<Blob> => recapShareBlob(recap, format);

    const handleShare = async () => {
        if (typeof navigator.share === 'function') {
            try {
                const blob = await captureImage();
                const file = new File([blob], 'minggu-kamu.png', { type: 'image/png' });
                if (navigator.canShare?.({ files: [file] })) {
                    await navigator.share({ files: [file], title: 'Minggu Kamu · TemanLari' });
                    return;
                }
            } catch {
                // fall through to download
            }
        }
        await handleDownload();
    };

    const handleDownload = async () => {
        try {
            const blob = await captureImage();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'minggu-kamu.png';
            a.click();
            URL.revokeObjectURL(url);
            setStatus({ tone: 'ok', text: 'Gambar minggu kamu kesimpen.' });
        } catch {
            setStatus({ tone: 'err', text: 'Gagal simpen gambar. Coba lagi ya.' });
        }
    };

    const handleCopy = async () => {
        if (typeof ClipboardItem === 'undefined' || navigator.clipboard?.write === undefined) {
            setStatus({ tone: 'err', text: 'Browser ini belum dukung salin gambar. Pakai Simpan ya.' });
            return;
        }
        try {
            const blob = await captureImage();
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
            setStatus({ tone: 'ok', text: 'Gambar minggu kamu kesalin.' });
        } catch {
            setStatus({ tone: 'err', text: 'Gagal nyalin gambar. Coba Simpan aja.' });
        }
    };

    return (
        <AnimatePresence>
            <motion.div
                key="recap-share-backdrop"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                className="fixed inset-0 z-[51] flex items-center justify-center p-4"
                style={{ background: 'rgba(0,0,0,0.5)', backdropFilter: 'blur(6px)' }}
            >
                <motion.div
                    key="recap-share-panel"
                    ref={panelRef}
                    role="dialog"
                    aria-modal="true"
                    aria-label="Bagikan minggu ini"
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
                            <div className="font-mono text-[11px] font-bold uppercase tracking-[0.14em] text-ink-2">
                                Bagikan minggu ini
                            </div>
                            <div className="font-display text-xl tracking-tight text-ink">Minggu Kamu</div>
                        </div>
                        <div className="w-8" />
                    </div>

                    {/* Body — preview + format picker, scrolls on short screens. */}
                    <div className="flex flex-1 flex-col items-center gap-4 overflow-y-auto bg-cream-deep px-5 py-5">
                        <canvas
                            ref={canvasRef}
                            width={1080}
                            height={format === 'story' ? 1920 : 1080}
                            aria-label="Pratinjau gambar minggu kamu"
                            className="block rounded-2xl"
                            style={{ maxWidth: '100%', maxHeight: '52vh', boxShadow: '0 16px 48px rgba(31,39,71,0.25)' }}
                        />

                        <div className="grid w-full grid-cols-2 gap-2">
                            {(['story', 'feed'] as RecapFormat[]).map((f) => (
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
                                        className={cn('rounded-sm bg-sky/25', f === 'story' ? 'h-6 w-3.5' : 'h-5 w-5')}
                                    />
                                    {f === 'story' ? 'Potret · 9:16' : 'Persegi · 1:1'}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* CTAs — pinned footer. */}
                    <div className="flex flex-col gap-2 border-t border-cream-deep bg-cream px-5 py-4">
                        <PillButton tone="sky" onClick={handleShare} className="w-full justify-center py-3.5 font-semibold">
                            <Icon icon="mdi:share-variant" width={16} height={16} aria-hidden />
                            Bagikan
                        </PillButton>
                        <div className="grid grid-cols-2 gap-2">
                            <PillButton tone="ghost" onClick={handleDownload} className="w-full justify-center">
                                <Icon icon="mdi:tray-arrow-down" width={16} height={16} aria-hidden />
                                Simpan
                            </PillButton>
                            <PillButton tone="ghost" onClick={handleCopy} className="w-full justify-center">
                                <Icon icon="mdi:content-copy" width={16} height={16} aria-hidden />
                                Salin gambar
                            </PillButton>
                        </div>
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
        </AnimatePresence>
    );
}
