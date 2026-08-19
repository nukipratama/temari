import { cn } from '@/lib/cn';

/**
 * Shared "Read more" / "Show less" toggle used by clamped text blocks
 * (e.g. ExpandableQuote). Keeps the label copy and styling in one place so
 * callers can't drift.
 */
export default function ReadMoreToggle({
    expanded,
    onToggle,
    onSky = false,
}: Readonly<{ expanded: boolean; onToggle: () => void; onSky?: boolean }>) {
    return (
        <button
            type="button"
            onClick={onToggle}
            className={cn(
                'focus-ring mt-1 rounded font-mono text-[11px] font-semibold transition',
                onSky
                    ? 'text-horizon hover:text-horizon/80'
                    : 'text-horizon-ink hover:text-horizon-ink/80',
            )}
        >
            {expanded ? 'Show less' : 'Read more'}
        </button>
    );
}
