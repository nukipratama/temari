import { cn } from '@/lib/cn';

interface BrandMarkProps {
    /** Wordmark color tone — flip to 'cream' when the mark sits on a dark hero surface. */
    tone?: 'ink' | 'cream';
    className?: string;
}

export default function BrandMark({ tone = 'ink', className }: Readonly<BrandMarkProps>) {
    const wordColor = tone === 'cream' ? 'text-cream' : 'text-ink';

    return (
        <div className={cn('flex items-center gap-2.5', className)}>
            <BunnyGlyph size={28} tone={tone} />
            <span
                className={cn('font-display italic leading-none tracking-[-0.02em]', wordColor)}
                style={{ fontSize: 22 }}
            >
                TemanLari
            </span>
        </div>
    );
}

function BunnyGlyph({ size, tone }: Readonly<{ size: number; tone: 'ink' | 'cream' }>) {
    const isInk = tone === 'ink';
    const face = isInk ? 'var(--color-ink)' : 'var(--color-cream)';
    const blush = isInk ? 'var(--color-horizon)' : 'var(--color-horizon-deep)';
    const band = 'var(--color-horizon)';
    const features = isInk ? 'var(--color-cream)' : 'var(--color-ink)';
    const highlightOpacity = isInk ? 0.12 : 0.18;
    const earGradId = `brand-ear-${tone}`;
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
                <radialGradient id={earGradId} cx="0.5" cy="0.4" r="0.7">
                    <stop offset="0%" stopColor={blush} stopOpacity={0.85} />
                    <stop offset="100%" stopColor={blush} stopOpacity={0.55} />
                </radialGradient>
                <clipPath id={bodyClipId}>
                    <rect x="10" y="18" width="80" height="78" rx="28" />
                </clipPath>
            </defs>

            <ellipse cx="32" cy="8" rx="9" ry="16" fill={face} transform="rotate(-12 32 8)" />
            <ellipse cx="68" cy="8" rx="9" ry="16" fill={face} transform="rotate(12 68 8)" />
            <ellipse cx="32" cy="10" rx="4" ry="9" fill={`url(#${earGradId})`} transform="rotate(-12 32 10)" />
            <ellipse cx="68" cy="10" rx="4" ry="9" fill={`url(#${earGradId})`} transform="rotate(12 68 10)" />

            <rect x="10" y="18" width="80" height="78" rx="28" fill={face} />
            <g clipPath={`url(#${bodyClipId})`}>
                <ellipse cx="38" cy="22" rx="36" ry="20" fill="white" opacity={highlightOpacity} />
            </g>

            <rect x="10" y="40" width="80" height="14" fill={band} />
            <rect x="10" y="51" width="80" height="3" fill="black" opacity="0.12" />

            <circle cx="38" cy="68" r="4.5" fill={features} />
            <circle cx="62" cy="68" r="4.5" fill={features} />
            {isInk && (
                <>
                    <circle cx="39.5" cy="66.5" r="1.3" fill="white" opacity="0.9" />
                    <circle cx="63.5" cy="66.5" r="1.3" fill="white" opacity="0.9" />
                </>
            )}

            <path
                d="M 44 80 Q 50 85 56 80"
                fill="none"
                stroke={features}
                strokeWidth="2.4"
                strokeLinecap="round"
            />
        </svg>
    );
}
