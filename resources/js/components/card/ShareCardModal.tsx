import { Copy, Share2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import { useModal } from '@/hooks/useModal';
import { cn } from '@/lib/cn';
import { runCardImageUrl } from '@/lib/routes';
import { toggleButtonVariants, iconButtonVariants } from '@/lib/variants';

/** What the page hands the modal: the run, and the copy the share sheet uses. */
export interface ShareCardTarget {
    activityId: number;
    name: string;
    /** Activity detail page URL, the fallback when native file sharing isn't available. */
    shareUrl: string;
    /** Temari's card line. It rides along as the share sheet's text, never on the card itself. */
    quote: string | null;
}

export const CARD_STYLES = ['broadsheet', 'ticket', 'topo'] as const;
export type CardStyle = (typeof CARD_STYLES)[number];

const STYLE_LABELS: Record<CardStyle, string> = {
    broadsheet: 'broadsheet',
    ticket: 'ticket',
    topo: 'topo plate',
};

const ASPECTS = ['story', 'feed'] as const;
type CardAspect = (typeof ASPECTS)[number];

const FACTS = [
    { key: 'hr', label: 'heart rate' },
    { key: 'elevation', label: 'elevation' },
    { key: 'weather', label: 'weather' },
    { key: 'badges', label: 'badges' },
] as const;
type FactKey = (typeof FACTS)[number]['key'];

const ALL_FACTS: Record<FactKey, boolean> = {
    hr: true,
    elevation: true,
    weather: true,
    badges: true,
};

interface ShareCardModalProps {
    card: ShareCardTarget | null;
    onClose: () => void;
}

export default function ShareCardModal({
    card,
    onClose,
}: Readonly<ShareCardModalProps>) {
    const [style, setStyle] = useState<CardStyle>('broadsheet');
    const [aspect, setAspect] = useState<CardAspect>('story');
    const [facts, setFacts] = useState<Record<FactKey, boolean>>(ALL_FACTS);
    // Keyed by URL rather than reset in an effect: a new tuple is a new render
    // attempt, and the old failure says nothing about it.
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    // Transient status under the CTAs: confirms a copy/share that has no native
    // UI of its own, or surfaces a failure instead of swallowing it silently.
    const [status, setStatus] = useState<{
        tone: 'ok' | 'err';
        text: string;
    } | null>(null);
    const panelRef = useRef<HTMLDivElement>(null);

    useModal(card !== null, panelRef, onClose);

    const imageUrl = useMemo(
        () =>
            card === null
                ? null
                : runCardImageUrl(card.activityId, {
                      style,
                      aspect,
                      ...facts,
                  }),
        [card, style, aspect, facts],
    );

    // Auto-clear the status line so it reads as a transient toast.
    useEffect(() => {
        if (status === null) return;
        const id = globalThis.setTimeout(() => setStatus(null), 2600);
        return () => globalThis.clearTimeout(id);
    }, [status]);

    if (card === null || imageUrl === null) return null;

    const failed = failedUrl === imageUrl;

    const fetchImage = async (): Promise<Blob> => {
        const response = await fetch(imageUrl);
        if (!response.ok) throw new Error(`card ${response.status}`);
        return response.blob();
    };

    const handleShare = async () => {
        const canNativeShare = typeof navigator.share === 'function';

        if (canNativeShare) {
            try {
                const file = new File(
                    [await fetchImage()],
                    `${card.name}.png`,
                    {
                        type: 'image/png',
                    },
                );
                if (navigator.canShare?.({ files: [file] })) {
                    await navigator.share({
                        files: [file],
                        title: `${card.name} · Temari`,
                    });
                    return;
                }
            } catch {
                // fall through to URL share
            }
            try {
                await navigator.share({
                    title: `${card.name} · Temari`,
                    text: card.quote ?? undefined,
                    url: card.shareUrl,
                });
            } catch {
                // user cancelled or API unavailable
            }
            return;
        }

        if (navigator.clipboard?.writeText !== undefined) {
            try {
                await navigator.clipboard.writeText(card.shareUrl);
                setStatus({ tone: 'ok', text: 'activity link copied.' });
            } catch {
                setStatus({ tone: 'err', text: 'failed to copy link.' });
            }
            return;
        }

        setStatus({
            tone: 'err',
            text: "this browser doesn't support sharing.",
        });
    };

    const handleCopy = async () => {
        if (
            typeof ClipboardItem === 'undefined' ||
            navigator.clipboard?.write === undefined
        ) {
            setStatus({
                tone: 'err',
                text: "this browser doesn't support copying images. use share instead.",
            });
            return;
        }
        try {
            await navigator.clipboard.write([
                new ClipboardItem({ 'image/png': await fetchImage() }),
            ]);
            setStatus({ tone: 'ok', text: 'card image copied.' });
        } catch {
            setStatus({
                tone: 'err',
                text: 'failed to copy image. try share instead.',
            });
        }
    };

    return (
        <div
            className="backdrop-reveal fixed inset-0 z-[51] flex items-center justify-center p-4"
            style={{
                background: 'rgba(0,0,0,0.5)',
                backdropFilter: 'blur(6px)',
            }}
        >
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                className="panel-reveal flex w-full max-w-md flex-col overflow-hidden rounded-xl bg-card shadow-e4"
                style={{ maxHeight: '92dvh' }}
            >
                {/* Header — pinned. */}
                <div className="flex items-center gap-3 border-b border-border px-5 py-3.5">
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className={iconButtonVariants({ size: 'sm' })}
                    >
                        <Icon icon={X} width={16} height={16} />
                    </button>
                    <div className="flex-1 text-center">
                        <div className="text-label-micro text-text-2">
                            Share card
                        </div>
                        <div className="font-serif text-xl tracking-tight text-foreground">
                            {card.name}
                        </div>
                    </div>
                    <div className="w-8" />
                </div>

                {/* Body — preview + pickers, scrolls on short screens. The image
                    IS the export: the same URL feeds the preview, the share sheet
                    and the copy, so nothing on screen can drift from the file. */}
                <div className="flex flex-1 flex-col items-center gap-4 overflow-y-auto bg-muted px-5 py-5">
                    {failed ? (
                        <p
                            role="status"
                            className="py-10 text-center font-sans text-sm text-ember-ink"
                        >
                            couldn&apos;t render this card. try another style.
                        </p>
                    ) : (
                        <img
                            src={imageUrl}
                            alt={`Preview of ${card.name}`}
                            onError={() => setFailedUrl(imageUrl)}
                            className="block rounded-lg"
                            style={{ maxWidth: '100%', maxHeight: '52vh' }}
                        />
                    )}

                    {/* Aspect picker */}
                    <div className="grid w-full grid-cols-2 gap-2">
                        {ASPECTS.map((a) => (
                            <button
                                key={a}
                                type="button"
                                onClick={() => setAspect(a)}
                                aria-pressed={aspect === a}
                                className={cn(
                                    'focus-ring flex items-center justify-center gap-2 rounded-xl p-2.5 text-xs font-medium transition',
                                    aspect === a
                                        ? 'border-2 border-border-strong bg-card font-semibold text-foreground'
                                        : 'border-2 border-transparent bg-card text-text-2 hover:border-border',
                                )}
                            >
                                <span
                                    aria-hidden
                                    className={cn(
                                        'rounded-sm bg-sky/25',
                                        a === 'story' ? 'h-6 w-3.5' : 'h-5 w-5',
                                    )}
                                />
                                {a === 'story'
                                    ? 'portrait · 9:16'
                                    : 'square · 1:1'}
                            </button>
                        ))}
                    </div>

                    {/* Style picker — all three print styles, always available. */}
                    <div className="flex w-full gap-2">
                        {CARD_STYLES.map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => setStyle(s)}
                                aria-pressed={style === s}
                                className={cn(
                                    toggleButtonVariants({
                                        selected: style === s,
                                        size: 'md',
                                    }),
                                    'flex-1',
                                )}
                            >
                                {STYLE_LABELS[s]}
                            </button>
                        ))}
                    </div>

                    {/* Optional facts — everything else on the card is always on. */}
                    <div className="flex w-full flex-wrap justify-center gap-2">
                        {FACTS.map(({ key, label }) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() =>
                                    setFacts((prev) => ({
                                        ...prev,
                                        [key]: !prev[key],
                                    }))
                                }
                                aria-pressed={facts[key]}
                                className={cn(
                                    toggleButtonVariants({
                                        selected: facts[key],
                                        size: 'sm',
                                    }),
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* CTAs — pinned footer. */}
                <div className="flex flex-col gap-2 border-t border-border bg-card px-5 py-4">
                    <PillButton
                        tone="sky"
                        onClick={handleShare}
                        className="w-full justify-center py-3.5 font-semibold"
                    >
                        <Icon
                            icon={Share2}
                            width={16}
                            height={16}
                            aria-hidden
                        />
                        Share
                    </PillButton>
                    <PillButton
                        tone="ghost"
                        onClick={handleCopy}
                        className="w-full justify-center"
                    >
                        <Icon icon={Copy} width={16} height={16} aria-hidden />
                        Copy image
                    </PillButton>
                    {status !== null && (
                        <p
                            role="status"
                            aria-live="polite"
                            className={cn(
                                'text-center font-sans text-xs',
                                status.tone === 'ok'
                                    ? 'text-leaf-ink'
                                    : 'text-ember-ink',
                            )}
                        >
                            {status.text}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
