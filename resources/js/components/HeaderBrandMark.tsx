import { cn } from '@/lib/cn';

interface HeaderBrandMarkProps {
    className?: string;
    /** Extra classes on the wordmark span — lets a cramped host (e.g. the mobile top bar) hide it responsively. */
    wordmarkClassName?: string;
}

/**
 * The persistent shell header's brand mark: the frozen prototype's abstract
 * ring glyph + lowercase wordmark (`TemariMark.tsx`). Distinct from
 * BrandMark's mascot-face glyph, which keeps rendering everywhere else
 * (hero avatars, Kartu art, share cards) — see plan/README.md §5 fork 2.
 */
export default function HeaderBrandMark({
    className,
    wordmarkClassName,
}: Readonly<HeaderBrandMarkProps>) {
    return (
        <div className={cn('flex items-center gap-2', className)}>
            <TemariRingGlyph size={22} />
            <span
                className={cn(
                    'text-sm leading-none font-extrabold tracking-tight text-foreground',
                    wordmarkClassName,
                )}
            >
                temari
            </span>
        </div>
    );
}

function TemariRingGlyph({ size }: Readonly<{ size: number }>) {
    return (
        <svg
            aria-hidden
            width={size}
            height={size}
            viewBox="0 0 100 100"
            className="shrink-0"
        >
            <g fill="none" strokeWidth="11" strokeLinecap="round">
                <path
                    stroke="var(--color-horizon)"
                    d="M50 12.5 A37.5 37.5 0 1 1 31.25 17.52"
                />
                <path
                    stroke="var(--color-foreground)"
                    d="M50 27 A23 23 0 1 1 30.09 61.5"
                />
            </g>
        </svg>
    );
}
