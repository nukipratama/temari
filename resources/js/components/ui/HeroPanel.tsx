import { type CSSProperties, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { dawnRayStyle, glowStyle, GLOW_COLORS, noiseFilterStyle } from '@/lib/styles';

interface HeroPanelProps {
    children: ReactNode;
    /** When true (default), applies the pre-dawn 160deg sky→sky-deep→sky-2 gradient. */
    gradient?: boolean;
    /** When true (default), renders built-in ambient glow decorations. */
    decorative?: boolean;
    className?: string;
    style?: CSSProperties;
}

const GRADIENT = 'bg-[linear-gradient(160deg,var(--color-sky-deep)_0%,var(--color-sky)_60%,var(--color-sky-2)_100%)]';

interface StarDot { x: number; y: number; size: number; opacity: number }

/** Deterministic star field — scattered dots across the upper sky area. */
const STARS: StarDot[] = [
    // Top band — densest (rows 0-5)
    [2, 4, 8, 12, 16, 20, 24, 28, 33, 38, 42, 47, 52, 56, 60, 64, 68, 72, 76, 80, 84, 88, 92, 96].map((x, i) => ({
        x: x + (i % 3), y: 2 + (i % 5) * 0.7, size: i % 3 === 1 ? 3 : 2, opacity: 0.18 + (i % 4) * 0.05,
    })),
    [5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60, 65, 70, 75, 80, 85, 90, 95].map((x, i) => ({
        x: x + (i % 2), y: 6 + (i % 4) * 0.8, size: i % 4 === 0 ? 3 : 2, opacity: 0.15 + (i % 5) * 0.04,
    })),
    [3, 7, 13, 18, 23, 28, 33, 38, 43, 48, 53, 58, 63, 68, 73, 78, 83, 88, 93, 97].map((x, i) => ({
        x: x + (i % 3), y: 10 + (i % 5) * 0.6, size: i % 3 === 2 ? 3 : 2, opacity: 0.20 + (i % 3) * 0.04,
    })),
    [8, 14, 22, 28, 35, 42, 48, 55, 62, 68, 75, 82, 88, 95].map((x, i) => ({
        x: x + (i % 2), y: 14 + (i % 4) * 0.9, size: 2, opacity: 0.12 + (i % 6) * 0.04,
    })),
    [5, 12, 20, 26, 32, 38, 45, 52, 58, 65, 72, 78, 85, 92].map((x, i) => ({
        x, y: 18 + (i % 3) * 0.8, size: i % 4 === 0 ? 3 : 2, opacity: 0.15 + (i % 5) * 0.03,
    })),
    [10, 18, 25, 32, 40, 48, 55, 62, 70, 78, 85, 92].map((x, i) => ({
        x: x + (i % 3), y: 22 + (i % 2) * 0.7, size: 2, opacity: 0.10 + (i % 4) * 0.04,
    })),
    // Lower band — sparser, fainter (rows 6-7)
    [15, 25, 35, 45, 55, 65, 75, 85].map((x, i) => ({
        x, y: 27 + (i % 3) * 0.8, size: 2, opacity: 0.08 + (i % 3) * 0.03,
    })),
    [20, 30, 40, 50, 60, 70, 80].map((x, i) => ({
        x: x + (i % 2), y: 32 + (i % 2) * 0.9, size: 1.5, opacity: 0.06 + (i % 4) * 0.02,
    })),
].flat();

export default function HeroPanel({ children, gradient = true, decorative = true, className, style }: Readonly<HeroPanelProps>) {
    return (
        <div
            className={cn(
                'relative rounded-2xl px-6 py-6 text-cream sm:px-8 sm:py-7',
                gradient ? GRADIENT : 'bg-sky',
                className,
            )}
            style={style}
        >
            {decorative && (
                <>
                    {/* Dawn ray — diagonal beam across the hero */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0"
                        style={dawnRayStyle()}
                    />

                    {/* Grain/noise texture */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute inset-0 opacity-[0.07] mix-blend-overlay"
                        style={noiseFilterStyle()}
                    />

                    {/* Diffuse backlight — soft glow from below */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -inset-x-20 bottom-0 h-[60%] rounded-full opacity-60"
                        style={{
                            background: 'radial-gradient(ellipse 80% 60% at 50% 100%, rgba(232,160,118,0.12) 0%, transparent 80%)',
                        }}
                    />

                    {/* Main content area glow — fills the center behind text */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute left-[5%] top-0 h-full w-[90%] opacity-40"
                        style={{
                            background: 'radial-gradient(ellipse 100% 70% at 50% 25%, rgba(232,160,118,0.2) 0%, rgba(232,160,118,0.05) 40%, transparent 70%)',
                        }}
                    />

                    {/* Outer depth ring — sits behind the whole content column */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute left-1/2 top-1/3 h-[500px] w-[500px] -translate-x-1/2 -translate-y-1/2 rounded-full opacity-40"
                        style={glowStyle(GLOW_COLORS.horizon.r, GLOW_COLORS.horizon.g, GLOW_COLORS.horizon.b, 0.08, '55%')}
                    />

                    {/* Top-right ember accent */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -right-12 -top-12 h-72 w-72 rounded-full"
                        style={glowStyle(GLOW_COLORS.ember.r, GLOW_COLORS.ember.g, GLOW_COLORS.ember.b, 0.5, '50%')}
                    />

                    {/* Bottom-left leaf glow — grounding earth tone */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -bottom-10 -left-10 h-48 w-48 rounded-full"
                        style={glowStyle(GLOW_COLORS.leaf.r, GLOW_COLORS.leaf.g, GLOW_COLORS.leaf.b, 0.35, '55%')}
                    />

                    {/* Subtle cool accent top-left */}
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -left-6 -top-6 h-36 w-36 rounded-full"
                        style={glowStyle(GLOW_COLORS.sky.r, GLOW_COLORS.sky.g, GLOW_COLORS.sky.b, 0.25, '60%')}
                    />

                    {/* Star field — generated for consistent coverage */}
                    {STARS.map((s, i) => (
                        <span
                            key={i}
                            aria-hidden
                            className="pointer-events-none absolute rounded-full bg-cream"
                            style={{
                                left: `${s.x}%`,
                                top: `${s.y}%`,
                                width: `${s.size}px`,
                                height: `${s.size}px`,
                                opacity: s.opacity,
                            }}
                        />
                    ))}
                </>
            )}
            {children}
        </div>
    );
}
