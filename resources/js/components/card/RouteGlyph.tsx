import { useMemo } from 'react';
import { cn } from '@/lib/cn';
import { BunnyGlyph } from '@/components/BrandMark';
import { projectPolyline } from '@/lib/route';
import type { Rarity } from '@/types/inertia';

interface RouteGlyphProps {
    /** Google-encoded route polyline. Drawn as a static path when present. */
    polyline?: string | null;
    /** Per-km pace seconds — drawn as a bar shape when there's no polyline. */
    paceShape?: ReadonlyArray<number> | null;
    rarity: Rarity;
    /** Explicit stroke color (loot-ladder hex). Falls back to the rarity token. */
    color?: string;
    className?: string;
}

const VB_W = 100;
const VB_H = 64;
const PAD = 8;
const MAX_POINTS = 120;
const MAX_BARS = 16;

/**
 * The card's art window: a lightweight **static** render of the run's identity.
 * Decodes the route polyline into one normalized SVG path (no Leaflet); falls
 * back to a pace-shape bar glyph, then to a faint Temari watermark, so every
 * card — including treadmill runs with no GPS — gets a filled window.
 *
 * Variants are tagged via `data-variant` for tests and styling.
 */
export default function RouteGlyph({ polyline, paceShape, rarity, color, className }: Readonly<RouteGlyphProps>) {
    const stroke = color ?? `var(--color-rarity-${rarity})`;
    const fill = color
        ? `color-mix(in oklab, ${color} 14%, transparent)`
        : `color-mix(in oklab, var(--color-rarity-${rarity}) 14%, transparent)`;

    const route = useMemo(() => projectPolyline(polyline, VB_W, VB_H, PAD, MAX_POINTS), [polyline]);
    if (route !== null) {
        const d = route.points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' ');
        return (
            <svg
                aria-hidden
                data-variant="route"
                viewBox={`0 0 ${VB_W} ${VB_H}`}
                preserveAspectRatio="xMidYMid meet"
                className={cn('block h-full w-full', className)}
            >
                <path
                    d={d}
                    fill={fill}
                    stroke={stroke}
                    strokeWidth={3.8}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    opacity={0.95}
                    style={{ filter: `drop-shadow(0 0 1.5px ${stroke})` }}
                />
                <circle cx={route.start[0]} cy={route.start[1]} r={3} fill={stroke} />
            </svg>
        );
    }

    const bars = barsFor(paceShape);
    if (bars !== null) {
        return (
            <svg
                aria-hidden
                data-variant="pace"
                viewBox={`0 0 ${VB_W} ${VB_H}`}
                preserveAspectRatio="none"
                className={cn('block h-full w-full', className)}
            >
                {bars.map((b) => (
                    <rect
                        key={b.x}
                        x={b.x}
                        y={VB_H - PAD - b.h}
                        width={b.w}
                        height={b.h}
                        rx={1}
                        fill={stroke}
                        opacity={b.best ? 0.9 : 0.4}
                    />
                ))}
            </svg>
        );
    }

    return (
        <div
            aria-hidden
            data-variant="glyph"
            className={cn('relative flex h-full w-full items-center justify-center overflow-hidden', className)}
        >
            <span className="absolute inset-0 opacity-[0.1]" style={{ backgroundColor: stroke }} />
            <span className="relative opacity-50">
                <BunnyGlyph size={56} tone="ink" />
            </span>
        </div>
    );
}

/**
 * Bucket per-km paces into up to MAX_BARS bars (faster = taller), mirroring the
 * SplitsSparkline shape logic. Returns null when there's no pace data.
 */
function barsFor(paceShape?: ReadonlyArray<number> | null): Array<{ x: number; w: number; h: number; best: boolean }> | null {
    if (paceShape == null || paceShape.length === 0) {
        return null;
    }

    const bucketSize = paceShape.length <= MAX_BARS ? 1 : Math.ceil(paceShape.length / MAX_BARS);
    const buckets: number[] = [];
    for (let i = 0; i < paceShape.length; i += bucketSize) {
        const chunk = paceShape.slice(i, i + bucketSize);
        buckets.push(chunk.reduce((sum, p) => sum + p, 0) / chunk.length);
    }

    const fastest = Math.min(...buckets);
    const slowest = Math.max(...buckets);
    const range = slowest - fastest;
    const innerW = VB_W - PAD * 2;
    const innerH = VB_H - PAD * 2;
    const gap = 1.5;
    const barW = (innerW - gap * (buckets.length - 1)) / buckets.length;

    return buckets.map((pace, i) => {
        const norm = range > 0 ? (slowest - pace) / range : 1; // faster pace → taller
        return {
            x: PAD + i * (barW + gap),
            w: barW,
            h: norm * (innerH * 0.78) + innerH * 0.22,
            best: pace === fastest,
        };
    });
}
