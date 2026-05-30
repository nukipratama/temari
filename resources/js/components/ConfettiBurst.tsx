import { motion } from 'framer-motion';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useReducedMotion } from '@/hooks/useReducedMotion';

interface Particle {
    id: number;
    x: number;
    y: number;
    rotate: number;
    color: string;
    delay: number;
    duration: number;
}

// Daybreak palette spread (leaf, ember, citrus, horizon, citrus-deep, mumet).
// Mirrors app.css @theme; Chart/SVG-style particle fills can't read CSS vars.
const COLORS = ['#6B8E6F', '#C4623F', '#D9B23A', '#E8A076', '#B8941E', '#7B5BB6'];

interface ConfettiBurstProps {
    /** Each unique key triggers a new burst. */
    burstKey: string | number | null;
    /** Particle count. Default 30. */
    count?: number;
    /** Total animation duration in ms before unmount. Default 2500. */
    durationMs?: number;
}

/**
 * Viewport-wide confetti burst. Mounts when `burstKey` changes from null;
 * auto-unmounts after `durationMs`. Reduced-motion users get a silent no-op.
 */
export default function ConfettiBurst({ burstKey, count = 30, durationMs = 2500 }: Readonly<ConfettiBurstProps>) {
    const [visible, setVisible] = useState(false);
    const activeKeyRef = useRef<string | number | null>(null);
    const reduced = useReducedMotion();

    useEffect(() => {
        if (burstKey === null || burstKey === activeKeyRef.current) return;
        activeKeyRef.current = burstKey;
        if (reduced) return;
        setVisible(true);
        const t = globalThis.setTimeout(() => setVisible(false), durationMs);
        return () => globalThis.clearTimeout(t);
    }, [burstKey, durationMs, reduced]);

    const particles = useMemo<Particle[]>(() => {
        if (!visible) return [];
        const list: Particle[] = [];
        for (let i = 0; i < count; i++) {
            list.push({
                id: i,
                x: Math.random() * 100,
                y: -10 - Math.random() * 20,
                rotate: Math.random() * 360,
                color: COLORS[i % COLORS.length],
                delay: Math.random() * 0.4,
                duration: 1.6 + Math.random() * 0.8,
            });
        }
        return list;
    }, [visible, count]);

    if (!visible) return null;

    return (
        <div
            aria-hidden
            className="pointer-events-none fixed inset-0 z-[60] overflow-hidden"
        >
            {particles.map((p) => (
                <motion.span
                    key={p.id}
                    initial={{ x: `${p.x}vw`, y: `${p.y}vh`, rotate: p.rotate, opacity: 1 }}
                    animate={{ y: '110vh', rotate: p.rotate + 360, opacity: [1, 1, 0] }}
                    transition={{ duration: p.duration, delay: p.delay, ease: 'easeIn' }}
                    style={{
                        position: 'absolute',
                        background: p.color,
                        width: 8,
                        height: 14,
                        borderRadius: 2,
                    }}
                />
            ))}
        </div>
    );
}
