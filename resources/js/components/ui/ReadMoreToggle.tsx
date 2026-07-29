/**
 * Shared "Baca selengkapnya" / "Tutup" toggle used by clamped text blocks
 * (e.g. ExpandableQuote). Keeps the label copy and styling in one place so
 * callers can't drift.
 */
export default function ReadMoreToggle({ expanded, onToggle }: Readonly<{ expanded: boolean; onToggle: () => void }>) {
    return (
        <button
            type="button"
            onClick={onToggle}
            className="focus-ring mt-1 rounded font-mono text-[11px] font-semibold text-horizon transition hover:text-horizon/80"
        >
            {expanded ? 'Tutup' : 'Baca selengkapnya'}
        </button>
    );
}
