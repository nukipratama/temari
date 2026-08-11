import { cn } from '@/lib/cn';

interface BrandMarkProps {
    /** Wordmark color tone — flip to 'cream' when the mark sits on a dark hero surface. */
    tone?: 'ink' | 'cream';
    className?: string;
    /** Extra classes on the wordmark span — lets a cramped host (e.g. the mobile top bar) hide it responsively. */
    wordmarkClassName?: string;
}

export default function BrandMark({
    tone = 'ink',
    className,
    wordmarkClassName,
}: Readonly<BrandMarkProps>) {
    const wordColor = tone === 'cream' ? 'text-cream' : 'text-ink';

    return (
        <div className={cn('flex items-center gap-2.5', className)}>
            <TemariGlyph size={28} tone={tone} />
            <span
                className={cn(
                    'font-mono font-bold leading-none tracking-[-0.02em]',
                    wordColor,
                    wordmarkClassName,
                )}
                style={{ fontSize: 20 }}
            >
                Temari
            </span>
        </div>
    );
}

export function TemariGlyph({
    size,
    tone,
}: Readonly<{ size: number; tone: 'ink' | 'cream' }>) {
    const isInk = tone === 'ink';
    const face = isInk ? 'var(--color-ink)' : 'var(--color-cream)';
    const band = 'var(--color-horizon)';
    const features = isInk ? 'var(--color-cream)' : 'var(--color-ink)';
    const highlightOpacity = isInk ? 0.12 : 0.18;
    const bodyClipId = `brand-body-clip-${tone}`;

    return (
        <svg
            aria-hidden
            width={size}
            height={size}
            viewBox="0 0 100 100"
            className="shrink-0 overflow-visible"
        >
            <defs>
                <clipPath id={bodyClipId}>
                    <circle cx="50" cy="52" r="42" />
                </clipPath>
            </defs>

            {/* Thread knot at the crown */}
            <path
                d="M 42 10 Q 50 4 58 10 Q 52 12 50 16 Q 48 12 42 10 Z"
                fill={face}
            />

            <circle cx="50" cy="52" r="42" fill={face} />
            <g clipPath={`url(#${bodyClipId})`}>
                <ellipse
                    cx="38"
                    cy="18"
                    rx="38"
                    ry="20"
                    fill="white"
                    opacity={highlightOpacity}
                />
                <rect x="8" y="34" width="84" height="13" fill={band} />
                <rect
                    x="8"
                    y="44"
                    width="84"
                    height="3"
                    fill="black"
                    opacity="0.12"
                />
                {/* Thin trim lines echo the full mascot's wound-thread band edges */}
                <rect
                    x="8"
                    y="34.5"
                    width="84"
                    height="1"
                    fill="black"
                    opacity="0.14"
                />
                <rect
                    x="8"
                    y="46"
                    width="84"
                    height="1"
                    fill="black"
                    opacity="0.14"
                />
            </g>

            <circle cx="38" cy="62" r="4.5" fill={features} />
            <circle cx="62" cy="62" r="4.5" fill={features} />
            {isInk && (
                <>
                    <circle
                        cx="39.5"
                        cy="60.5"
                        r="1.3"
                        fill="white"
                        opacity="0.9"
                    />
                    <circle
                        cx="63.5"
                        cy="60.5"
                        r="1.3"
                        fill="white"
                        opacity="0.9"
                    />
                </>
            )}

            <path
                d="M 44 74 Q 50 79 56 74"
                fill="none"
                stroke={features}
                strokeWidth="2.4"
                strokeLinecap="round"
            />
        </svg>
    );
}
