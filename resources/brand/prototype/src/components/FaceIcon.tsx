export function FaceIcon({
    size = 40,
    ring,
    fill,
    feature,
}: Readonly<{ size?: number; ring: string; fill: string; feature: string }>) {
    return (
        <svg
            viewBox="0 0 100 100"
            width={size}
            height={size}
            className="flex-none"
            aria-hidden="true"
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
