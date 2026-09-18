import {
    Award,
    Cloud,
    Copy,
    Download,
    Heart,
    Mountain,
    Share,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import type { Print } from '@/lib/card/print';

import { useCardPrints, type PrintState } from '@/components/card/useCardPrints';
import { Icon, type IconComponent } from '@/components/ui/Icon';
import { useModal } from '@/hooks/useModal';
import { hasFact } from '@/lib/card/facts';
import {
    ALL_FACTS,
    CARD_ASPECTS,
    CARD_STYLES,
    type CardAspect,
    type CardFactsPayload,
    type CardOptions,
    type CardStyle,
} from '@/lib/card/types';
import { cn } from '@/lib/cn';

/** What the page hands the modal: the run, its facts, and the share copy. */
export interface ShareCardTarget {
    name: string;
    /** Everything a print draws, resolved server-side with the run page. */
    facts: CardFactsPayload | null;
    /** Run detail page URL, the fallback when native file sharing isn't available. */
    shareUrl: string;
    /** Temari's card line. It rides along as the share sheet's text, never on the card itself. */
    quote: string | null;
}

const STYLE_LABELS: Record<CardStyle, string> = {
    broadsheet: 'broadsheet',
    ticket: 'ticket',
    topo: 'topo plate',
};

/** The story-app scrim and its label, both fixed dark: see the overlay below. */
const SAFE_BAND = {
    background: 'rgba(11,16,23,0.46)',
    borderColor: 'rgba(241,245,248,0.55)',
};
const SAFE_LABEL = { color: 'rgba(241,245,248,0.82)' };

const FACT_CHIPS: Array<{
    key: keyof CardOptions;
    label: string;
    icon: IconComponent;
}> = [
    { key: 'hr', label: 'HR', icon: Heart },
    { key: 'elevation', label: 'elevation', icon: Mountain },
    { key: 'weather', label: 'weather', icon: Cloud },
    { key: 'badges', label: 'badges', icon: Award },
];

/**
 * The share popup: a bottom sheet at phone width, a centred dialog on a
 * desktop. The three prints sit side by side in a clipped carousel with their
 * neighbours peeking, the story/feed toggle sits above them, and the chips
 * below toggle the optional facts. A chip for a fact this run does not have is
 * hidden rather than disabled.
 *
 * Everything on screen is drawn in the browser, so a switch is a local redraw:
 * a tuple already drawn paints instantly, and only a genuinely new one shows
 * any progress at all.
 */
export default function ShareCardModal({
    card,
    onClose,
}: Readonly<{ card: ShareCardTarget | null; onClose: () => void }>) {
    const [style, setStyle] = useState<CardStyle>('broadsheet');
    const [aspect, setAspect] = useState<CardAspect>('story');
    const [facts, setFacts] = useState<CardOptions>(ALL_FACTS);
    const [status, setStatus] = useState<{
        tone: 'ok' | 'err';
        text: string;
    } | null>(null);
    const panelRef = useRef<HTMLDivElement>(null);
    const swipeStartRef = useRef<number | null>(null);

    useModal(card !== null, panelRef, onClose);

    const payload = card?.facts ?? null;
    const chips = useMemo(
        () =>
            payload === null
                ? []
                : FACT_CHIPS.filter(({ key }) => hasFact(payload, key)),
        [payload],
    );

    const { states, retry } = useCardPrints(payload, facts, aspect, style);

    // Auto-clear the status line so it reads as a transient toast.
    useEffect(() => {
        if (status === null) return;
        const id = globalThis.setTimeout(() => setStatus(null), 2600);
        return () => globalThis.clearTimeout(id);
    }, [status]);

    if (card === null) return null;

    const current = states[style];
    const print = current.print;
    const index = CARD_STYLES.indexOf(style);

    const step = (delta: number) => {
        const next = index + delta;
        if (next >= 0 && next < CARD_STYLES.length) setStyle(CARD_STYLES[next]);
    };

    const fileName = `${card.name.replace(/[^\w-]+/g, '-').toLowerCase()}-${style}.png`;

    const handleShare = async () => {
        const file =
            print === null
                ? null
                : new File([print.blob], fileName, { type: 'image/png' });

        if (typeof navigator.share === 'function') {
            if (file !== null && navigator.canShare?.({ files: [file] })) {
                try {
                    await navigator.share({
                        files: [file],
                        title: `${card.name} · Temari`,
                    });
                    return;
                } catch {
                    // fall through to the URL share
                }
            }
            try {
                await navigator.share({
                    title: `${card.name} · Temari`,
                    text: card.quote ?? undefined,
                    url: card.shareUrl,
                });
            } catch {
                // user cancelled or the sheet is unavailable
            }
            return;
        }

        if (navigator.clipboard?.writeText !== undefined) {
            try {
                await navigator.clipboard.writeText(card.shareUrl);
                setStatus({ tone: 'ok', text: 'run link copied.' });
            } catch {
                setStatus({ tone: 'err', text: "couldn't copy the link." });
            }
            return;
        }

        setStatus({ tone: 'err', text: "this browser can't share." });
    };

    const handleCopy = async () => {
        if (
            print === null ||
            typeof ClipboardItem === 'undefined' ||
            navigator.clipboard?.write === undefined
        ) {
            setStatus({
                tone: 'err',
                text: "this browser can't copy images. use share instead.",
            });
            return;
        }
        try {
            await navigator.clipboard.write([
                new ClipboardItem({ 'image/png': print.blob }),
            ]);
            setStatus({ tone: 'ok', text: 'print copied.' });
        } catch {
            setStatus({
                tone: 'err',
                text: "couldn't copy the print. try share instead.",
            });
        }
    };

    const handleDownload = () => {
        if (print === null) return;
        const link = document.createElement('a');
        link.href = print.url;
        link.download = fileName;
        link.click();
    };

    const ready = print !== null;

    return (
        <div
            className="backdrop-reveal fixed inset-0 z-[50]"
            style={{
                background: 'rgba(0,0,0,0.5)',
                backdropFilter: 'blur(6px)',
            }}
        >
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-label="share this run"
                className={cn(
                    'panel-reveal fixed inset-x-0 bottom-0 z-[51] flex max-h-[96dvh] flex-col overflow-y-auto rounded-t-2xl bg-card pb-3.5 text-card-foreground shadow-e4',
                    'min-[900px]:inset-x-auto min-[900px]:bottom-auto min-[900px]:left-1/2 min-[900px]:top-1/2 min-[900px]:w-[480px] min-[900px]:max-h-[94dvh] min-[900px]:-translate-x-1/2 min-[900px]:-translate-y-1/2 min-[900px]:rounded-2xl min-[900px]:pb-5',
                    aspect === 'story'
                        ? '[--print-h:372px] min-[900px]:[--print-h:486px]'
                        : '[--print-h:268px] min-[900px]:[--print-h:360px]',
                )}
                style={{
                    '--print-w':
                        aspect === 'story'
                            ? 'calc(var(--print-h) * 1080 / 1920)'
                            : 'var(--print-h)',
                } as React.CSSProperties}
            >
                <div className="mx-auto mt-2 h-1 w-9 rounded-full bg-border min-[900px]:hidden" />

                <div className="flex items-center gap-2.5 px-[18px] pb-1.5 pt-2.5 min-[900px]:px-[22px] min-[900px]:pb-2 min-[900px]:pt-4">
                    <div className="flex-1">
                        <div className="text-label-micro text-text-3">share</div>
                        <div className="font-serif text-xl italic leading-tight tracking-tight">
                            {card.name}
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="close"
                        className="focus-ring grid size-8 flex-none place-items-center rounded-full border border-border text-text-2"
                    >
                        <Icon icon={X} width={15} aria-hidden />
                    </button>
                </div>

                {/* Shape — above the print, so the toggle never moves under a thumb. */}
                <div
                    role="group"
                    aria-label="shape"
                    className="mx-[18px] mb-3.5 mt-1 flex gap-0.5 rounded-full bg-muted p-0.5 min-[900px]:mx-[22px]"
                >
                    {CARD_ASPECTS.map((option) => (
                        <button
                            key={option}
                            type="button"
                            onClick={() => setAspect(option)}
                            aria-pressed={aspect === option}
                            className={cn(
                                'focus-ring flex flex-1 items-center justify-center gap-[7px] rounded-full py-2 text-[0.8125rem] font-semibold transition',
                                aspect === option
                                    ? 'bg-card text-foreground shadow-e1'
                                    : 'text-text-3',
                            )}
                        >
                            <span
                                aria-hidden
                                className={cn(
                                    'rounded-[2px] border-[1.5px] border-current opacity-85',
                                    option === 'story'
                                        ? 'h-[17px] w-[11px]'
                                        : 'size-[15px]',
                                )}
                            />
                            {option}
                        </button>
                    ))}
                </div>

                {/* The prints, racked side by side and clipped by the stage, so
                    the neighbours bleed off the sheet instead of widening it. */}
                <div
                    role="group"
                    aria-label="print style"
                    tabIndex={0}
                    onKeyDown={(event) => {
                        if (event.key === 'ArrowLeft') step(-1);
                        if (event.key === 'ArrowRight') step(1);
                    }}
                    onTouchStart={(event) => {
                        swipeStartRef.current = event.touches[0].clientX;
                    }}
                    onTouchEnd={(event) => {
                        const from = swipeStartRef.current;
                        swipeStartRef.current = null;
                        if (from === null) return;
                        const travel = event.changedTouches[0].clientX - from;
                        if (Math.abs(travel) > 40) step(travel < 0 ? 1 : -1);
                    }}
                    className="focus-ring relative h-[var(--print-h)] overflow-hidden"
                >
                    <div
                        className="absolute left-1/2 top-0 flex gap-[18px] transition-transform duration-300"
                        style={{
                            transform: `translateX(calc(-0.5 * var(--print-w) - ${index} * (var(--print-w) + 18px)))`,
                        }}
                    >
                        {CARD_STYLES.map((each) => (
                            <PrintSlot
                                key={each}
                                label={STYLE_LABELS[each]}
                                state={states[each]}
                                current={each === style}
                                story={aspect === 'story'}
                                onRetry={retry}
                            />
                        ))}
                    </div>
                </div>

                {aspect === 'story' && (
                    <p className="mx-[18px] mt-2.5 text-center text-label-micro text-text-3 min-[900px]:mx-[22px]">
                        shaded bands = what instagram covers
                    </p>
                )}

                {/* Style switcher — all three named, no suggested default. */}
                <div className="mx-[18px] mt-3.5 flex gap-1.5 rounded-full bg-muted p-[3px] min-[900px]:mx-[22px]">
                    {CARD_STYLES.map((each) => (
                        <button
                            key={each}
                            type="button"
                            onClick={() => setStyle(each)}
                            aria-pressed={style === each}
                            className={cn(
                                'focus-ring flex-1 rounded-full px-1 py-2 text-[0.78125rem] font-semibold transition',
                                style === each
                                    ? 'bg-card text-foreground shadow-e1'
                                    : 'text-text-3',
                            )}
                        >
                            {STYLE_LABELS[each]}
                        </button>
                    ))}
                </div>

                {chips.length > 0 && (
                    <div className="mx-[18px] mt-3.5 flex flex-wrap justify-center gap-[7px] min-[900px]:mx-[22px]">
                        {chips.map(({ key, label, icon }) => (
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
                                    'focus-ring inline-flex items-center gap-1.5 rounded-full border py-1.5 pl-2.5 pr-3 text-[0.78125rem] transition',
                                    facts[key]
                                        ? 'border-foreground bg-secondary font-semibold text-foreground'
                                        : 'border-border font-medium text-text-3',
                                )}
                            >
                                <Icon icon={icon} width={14} aria-hidden />
                                {label}
                            </button>
                        ))}
                    </div>
                )}

                {/* Three equal actions: the print is the thing, not the sharing. */}
                <div className="mx-[18px] mt-4 grid grid-cols-3 gap-2 min-[900px]:mx-[22px]">
                    <ActionTile
                        icon={Share}
                        label="share"
                        primary
                        disabled={!ready}
                        onClick={handleShare}
                    />
                    <ActionTile
                        icon={Copy}
                        label="copy"
                        disabled={!ready}
                        onClick={handleCopy}
                    />
                    <ActionTile
                        icon={Download}
                        label="download"
                        disabled={!ready}
                        onClick={handleDownload}
                    />
                </div>

                {status !== null && (
                    <p
                        role="status"
                        aria-live="polite"
                        className={cn(
                            'mt-2.5 text-center font-sans text-xs',
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
    );
}

function ActionTile({
    icon,
    label,
    primary = false,
    disabled,
    onClick,
}: Readonly<{
    icon: IconComponent;
    label: string;
    primary?: boolean;
    disabled: boolean;
    onClick: () => void;
}>) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'focus-ring flex flex-col items-center justify-center gap-1.5 rounded-xl border py-3 text-[0.78125rem] font-semibold disabled:opacity-40',
                primary
                    ? 'border-primary bg-primary text-sky'
                    : 'border-border text-foreground',
            )}
        >
            <Icon icon={icon} width={17} aria-hidden />
            {label}
        </button>
    );
}

/**
 * One slot in the rack. A print already drawn paints straight away; a new tuple
 * keeps the previous print on screen under a progress bar rather than blanking
 * it, and only a first draw with nothing to show gets a skeleton.
 */
function PrintSlot({
    label,
    state,
    current,
    story,
    onRetry,
}: Readonly<{
    label: string;
    state: PrintState;
    current: boolean;
    story: boolean;
    onRetry: () => void;
}>) {
    const showing: Print | null = state.print ?? state.previous;

    return (
        <div
            className={cn(
                'relative h-[var(--print-h)] w-[var(--print-w)] flex-none overflow-hidden rounded-md shadow-e3 transition',
                current ? 'opacity-100' : 'scale-90 opacity-[0.32]',
            )}
        >
            {state.failed ? (
                <div className="flex h-full flex-col items-center justify-center gap-3 border border-dashed border-line-strong bg-muted p-5 text-center">
                    <p className="max-w-[22ch] text-sm leading-normal text-foreground">
                        couldn&apos;t make the print.
                    </p>
                    <p className="text-xs text-text-3">
                        the run is fine, the image isn&apos;t.
                    </p>
                    <button
                        type="button"
                        onClick={onRetry}
                        className="focus-ring rounded-full border border-border-strong bg-card px-4 py-2 text-[0.8125rem] font-semibold text-foreground"
                    >
                        try again
                    </button>
                </div>
            ) : showing === null ? (
                <div className="skeleton size-full" aria-hidden />
            ) : (
                <>
                    <img
                        src={showing.url}
                        alt={`${label} print`}
                        className={cn(
                            'block size-full object-cover transition-opacity',
                            state.print === null ? 'opacity-[0.34]' : '',
                        )}
                    />
                    {/* Instagram's own chrome, previewed over the story print.
                        Drawn here and never by the renderer, so it cannot reach
                        the exported file. A fixed dark scrim rather than a
                        ground token: it stands for another app's UI, and it
                        must read the same on either ground. */}
                    {story && current && (
                        <div
                            aria-hidden
                            className="pointer-events-none absolute inset-0"
                        >
                            <div
                                className="absolute inset-x-0 top-0 flex h-[13.02%] items-start border-b border-dashed"
                                style={SAFE_BAND}
                            >
                                <span
                                    className="w-full px-[0.4375rem] py-[0.3125rem] font-mono text-[0.4375rem] uppercase tracking-[0.14em]"
                                    style={SAFE_LABEL}
                                >
                                    instagram ui
                                </span>
                            </div>
                            <div
                                className="absolute inset-x-0 bottom-0 flex h-[14.06%] items-end border-t border-dashed"
                                style={SAFE_BAND}
                            >
                                <span
                                    className="w-full px-[0.4375rem] py-[0.3125rem] text-right font-mono text-[0.4375rem] uppercase tracking-[0.14em]"
                                    style={SAFE_LABEL}
                                >
                                    instagram ui
                                </span>
                            </div>
                        </div>
                    )}
                    {state.print === null && (
                        <div className="absolute inset-x-0 bottom-0 h-[3px] bg-muted">
                            <span className="block h-full w-[62%] rounded-full bg-icon-accent" />
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
