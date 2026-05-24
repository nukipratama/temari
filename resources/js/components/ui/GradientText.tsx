import type { CSSProperties, ReactNode } from 'react';

type GradientPreset = 'horizon' | 'cream-sun';

interface GradientTextProps {
    preset: GradientPreset;
    /** Inline fontSize (clamp / px) — gradient text is usually paired with very large sizes. */
    fontSize: string;
    className?: string;
    children: ReactNode;
}

const PRESET_GRADIENT: Record<GradientPreset, string> = {
    horizon: 'linear-gradient(180deg, var(--color-horizon-deep), var(--color-citrus))',
    'cream-sun': 'linear-gradient(180deg, var(--color-cream), oklch(85% 0.10 50))',
};

export default function GradientText({ preset, fontSize, className, children }: Readonly<GradientTextProps>) {
    const style: CSSProperties = {
        fontSize,
        background: PRESET_GRADIENT[preset],
        WebkitBackgroundClip: 'text',
        WebkitTextFillColor: 'transparent',
        backgroundClip: 'text',
        color: 'transparent',
    };
    return <span className={className} style={style}>{children}</span>;
}
