import type { SVGProps } from 'react';

interface Position {
    x: number;
    y: number;
    rotate: number;
}

const POSITIONS: Position[] = [
    { x: 50, y: 6, rotate: 0 },
    { x: 94, y: 50, rotate: 90 },
    { x: 50, y: 94, rotate: 180 },
    { x: 6, y: 50, rotate: 270 },
];

interface StitchProps {
    op: string;
    pos: Position;
    color: string;
}

function Stitch({ op, pos, color }: StitchProps) {
    const transform = `translate(${pos.x} ${pos.y}) rotate(${pos.rotate})`;
    const sw = 1.5;
    switch (op) {
        case 'o':
            return (
                <g transform={transform}>
                    <circle r={4} fill="none" stroke={color} strokeWidth={sw} />
                </g>
            );
        case 'r':
            return (
                <g transform={transform}>
                    <path d="M -4 0 A 4 4 0 0 1 4 0" fill="none" stroke={color} strokeWidth={sw} />
                </g>
            );
        case 'c':
            return (
                <g transform={transform}>
                    <line x1={-4} y1={0} x2={4} y2={0} stroke={color} strokeWidth={sw} />
                    <line x1={0} y1={-4} x2={0} y2={4} stroke={color} strokeWidth={sw} />
                </g>
            );
        case 't':
            return (
                <g transform={transform}>
                    <polygon points="0,-5 4,3 -4,3" fill="none" stroke={color} strokeWidth={sw} />
                </g>
            );
        case 's':
            return (
                <g transform={transform}>
                    <polygon points="0,-5 1,-1 5,-1 2,1 3,5 0,2 -3,5 -2,1 -5,-1 -1,-1" fill={color} />
                </g>
            );
        case 'w':
            return (
                <g transform={transform}>
                    <path d="M -5 0 Q -3 -3 0 0 T 5 0" fill="none" stroke={color} strokeWidth={sw} />
                </g>
            );
        case 'v':
            return (
                <g transform={transform}>
                    <polyline points="-4,2 0,-3 4,2" fill="none" stroke={color} strokeWidth={sw} />
                </g>
            );
        case 'p':
            return (
                <g transform={transform}>
                    <line x1={-4} y1={-4} x2={4} y2={4} stroke={color} strokeWidth={sw} />
                    <line x1={-4} y1={4} x2={4} y2={-4} stroke={color} strokeWidth={sw} />
                </g>
            );
        case 'l':
            return (
                <g transform={transform}>
                    <line x1={-4} y1={4} x2={4} y2={-4} stroke={color} strokeWidth={sw} />
                </g>
            );
        case 'f':
            return (
                <g transform={transform}>
                    <rect x={-4} y={-1} width={8} height={2} fill={color} />
                </g>
            );
        case 'h':
            return (
                <g transform={transform}>
                    <line x1={-5} y1={0} x2={5} y2={0} stroke={color} strokeWidth={sw} />
                </g>
            );
        default:
            return (
                <g transform={transform}>
                    <circle r={1.5} fill={color} />
                </g>
            );
    }
}

function Accessory({ kind, color }: { kind: string | null; color: string }) {
    switch (kind) {
        case 'headband':
            return (
                <g>
                    <path d="M 15 24 Q 50 18 85 24" fill="none" stroke={color} strokeWidth={3} strokeLinecap="round" />
                    <circle cx={50} cy={21} r={2} fill={color} />
                </g>
            );
        case 'mata-ngantuk':
            return (
                <g stroke={color} strokeWidth={1.8} strokeLinecap="round" fill="none">
                    <path d="M 34 46 Q 39 50 44 46" />
                    <path d="M 56 46 Q 61 50 66 46" />
                </g>
            );
        case 'pita':
            return (
                <g fill={color}>
                    <polygon points="38,12 50,18 38,24" />
                    <polygon points="62,12 50,18 62,24" />
                    <circle cx={50} cy={18} r={2.5} />
                </g>
            );
        default:
            return null;
    }
}

interface TemariSigilProps extends SVGProps<SVGSVGElement> {
    pattern?: string;
    size?: number;
    color?: string;
    accessory?: string | null;
}

/**
 * Stitch-art mascot sigil. 4 cardinal stitch ops around a dashed face circle.
 * Ports resources/views/components/temari-sigil.blade.php verbatim.
 */
export default function TemariSigil({
    pattern = 'dddd',
    size = 96,
    color = 'currentColor',
    accessory = null,
    ...rest
}: TemariSigilProps) {
    const chars = (pattern.slice(0, 4) + 'dddd').slice(0, 4);
    return (
        <svg viewBox="0 0 100 100" width={size} height={size} aria-hidden {...rest}>
            <circle cx={50} cy={50} r={44} fill="none" stroke={color} strokeWidth={1} strokeDasharray="2 3" opacity={0.4} />
            {POSITIONS.map((pos, i) => (
                <Stitch key={i} op={chars[i] ?? 'd'} pos={pos} color={color} />
            ))}
            <Accessory kind={accessory} color={color} />
        </svg>
    );
}
