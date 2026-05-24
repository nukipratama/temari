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
    const face = tone === 'cream' ? 'var(--color-cream)' : 'var(--color-ink)';
    const band = 'var(--color-horizon)';
    const r = size * 0.28;

    return (
        <span
            aria-hidden
            className="relative inline-flex shrink-0"
            style={{ width: size, height: size }}
        >
            <span
                aria-hidden
                className="absolute rounded-full"
                style={{
                    top: -size * 0.18,
                    left: size * 0.16,
                    width: size * 0.18,
                    height: size * 0.32,
                    background: face,
                    transform: 'rotate(-12deg)',
                }}
            />
            <span
                aria-hidden
                className="absolute rounded-full"
                style={{
                    top: -size * 0.18,
                    right: size * 0.16,
                    width: size * 0.18,
                    height: size * 0.32,
                    background: face,
                    transform: 'rotate(12deg)',
                }}
            />
            <span
                aria-hidden
                className="relative block w-full overflow-hidden"
                style={{
                    height: size,
                    background: face,
                    borderRadius: r,
                }}
            >
                <span
                    aria-hidden
                    className="absolute inset-x-0"
                    style={{
                        top: size * 0.22,
                        height: size * 0.14,
                        background: band,
                    }}
                />
            </span>
        </span>
    );
}
