interface FaceIconProps {
    size?: number;
    /** Outer ring stroke. Mood-tinted where a surface carries a mood, brand horizon otherwise. */
    ring?: string;
    /** Face fill. */
    fill?: string;
    /** Brows, eyes and mouth. */
    feature?: string;
}

/**
 * Temari's face: a ring, a filled disc, two brows, two eyes and a smile.
 * Ported from the frozen prototype's
 * {@link ../../../brand/prototype/src/components/FaceIcon.tsx FaceIcon}, with
 * the app's `--color-*` tokens as defaults so both grounds are covered without
 * every call site restating them.
 */
export default function FaceIcon({
    size = 40,
    ring = 'var(--color-horizon)',
    fill = 'var(--color-card)',
    feature = 'var(--color-foreground)',
}: Readonly<FaceIconProps>) {
    return (
        <svg
            viewBox="0 0 100 100"
            width={size}
            height={size}
            className="flex-none"
            aria-hidden="true"
            data-face-icon
        >
            <circle
                cx="50"
                cy="52"
                r="41"
                fill="none"
                stroke={ring}
                strokeWidth="6"
            />
            <circle
                cx="50"
                cy="52"
                r="31"
                fill={fill}
                stroke={feature}
                strokeWidth="4.5"
            />
            <path
                d="M34.5 39.0 L45.5 37.5"
                fill="none"
                stroke={feature}
                strokeWidth="3.8"
                strokeLinecap="round"
            />
            <path
                d="M65.5 39.0 L54.5 37.5"
                fill="none"
                stroke={feature}
                strokeWidth="3.8"
                strokeLinecap="round"
            />
            <circle cx="40" cy="50" r="4.4" fill={feature} />
            <circle cx="60" cy="50" r="4.4" fill={feature} />
            <path
                d="M42 62.5 Q50 69 58 62.5"
                fill="none"
                stroke={feature}
                strokeWidth="3.8"
                strokeLinecap="round"
            />
        </svg>
    );
}
