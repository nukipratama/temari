interface TemariMarkProps {
    size?: number;
    /** Inner arc colour. Flip to cream when the mark sits on a dark surface. */
    color?: string;
    className?: string;
}

/**
 * The brand mark: two nested open arcs, the outer one on horizon. Ported from
 * the frozen prototype's
 * {@link ../../brand/prototype/src/components/rack/TemariMark.tsx TemariMark},
 * which is the only logo it draws — on its topbar and its login screen.
 *
 * This is the app's single brand mark: the shell header, the wordmark lockup,
 * the Kartu route-glyph fallback and both share-card renderers all draw it.
 */
export default function TemariMark({
    size = 22,
    color = 'var(--color-foreground)',
    className,
}: Readonly<TemariMarkProps>) {
    return (
        <svg
            aria-hidden
            width={size}
            height={size}
            viewBox="0 0 100 100"
            className={className}
        >
            <g fill="none" strokeWidth="11" strokeLinecap="round">
                <path
                    stroke="var(--color-horizon)"
                    d="M50 12.5 A37.5 37.5 0 1 1 31.25 17.52"
                />
                <path stroke={color} d="M50 27 A23 23 0 1 1 30.09 61.5" />
            </g>
        </svg>
    );
}
