import { cn } from '@/lib/cn';

interface TemariGlyphProps {
    size?: number;
    ringColor?: 'horizon' | 'leaf' | 'citrus';
    className?: string;
}

const RING_CLASS: Record<NonNullable<TemariGlyphProps['ringColor']>, string> = {
    horizon: 'border-horizon',
    leaf: 'border-leaf',
    citrus: 'border-citrus',
};

export default function TemariGlyph({
    size = 28,
    ringColor = 'horizon',
    className,
}: Readonly<TemariGlyphProps>) {
    return (
        <div
            className={cn(
                'flex shrink-0 items-center justify-center rounded-full border-2 bg-cream-deep',
                RING_CLASS[ringColor],
                className,
            )}
            style={{ width: size, height: size }}
            aria-hidden
        >
            <span
                className="font-display italic leading-none text-sky"
                style={{ fontSize: size * 0.5 }}
            >
                T
            </span>
        </div>
    );
}
