export function TemariMark({
    className,
    size = 22,
}: Readonly<{ className?: string; size?: number }>) {
    return (
        <svg
            viewBox="0 0 100 100"
            width={size}
            height={size}
            className={className}
            aria-hidden="true"
        >
            <g fill="none" strokeWidth="11" strokeLinecap="round">
                <path
                    stroke="var(--horizon)"
                    d="M50 12.5 A37.5 37.5 0 1 1 31.25 17.52"
                />
                <path
                    stroke="currentColor"
                    d="M50 27 A23 23 0 1 1 30.09 61.5"
                />
            </g>
        </svg>
    );
}
