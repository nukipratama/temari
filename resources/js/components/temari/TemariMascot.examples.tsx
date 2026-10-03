import { useState } from 'react';

import TemariMascot, {
    type MascotPose,
    POSES,
} from '@/components/temari/TemariMascot';
import { type CatalogueEntry } from '@/lib/catalogue';

const POSE_NAMES = Object.keys(POSES) as MascotPose[];

function Specimen({
    label,
    children,
}: Readonly<{ label: string; children: React.ReactNode }>) {
    return (
        <figure className="flex w-24 flex-col items-center gap-1">
            <div className="flex h-20 items-center justify-center">
                {children}
            </div>
            <figcaption className="text-meta">{label}</figcaption>
        </figure>
    );
}

function DrawIn() {
    const [replay, setReplay] = useState(0);

    return (
        <div className="flex items-end gap-4">
            <TemariMascot key={replay} pose="blazing" size={72} drawIn />
            <button
                type="button"
                className="focus-ring rounded-xs text-meta underline"
                onClick={() => setReplay((n) => n + 1)}
            >
                replay
            </button>
        </div>
    );
}

export default {
    name: 'TemariMascot',
    description:
        "The living brand mark: the logo's two arcs posed per run mood, plus neutral, concerned, sleepy for empty states and thinking for pending narration. Below 32px the face drops to eyes only.",
    usage: `<TemariMascot pose={run.mood} size={48} />`,
    states: [
        {
            name: 'every pose',
            render: () => (
                <div className="flex flex-wrap gap-2">
                    {POSE_NAMES.map((pose) => (
                        <Specimen key={pose} label={pose}>
                            <TemariMascot pose={pose} size={64} />
                        </Specimen>
                    ))}
                </div>
            ),
        },
        {
            name: 'sizes',
            render: () => (
                <div className="flex flex-wrap items-end gap-2">
                    {[22, 28, 48, 72].map((size) => (
                        <Specimen key={size} label={`${size}px`}>
                            <TemariMascot pose="easy" size={size} />
                        </Specimen>
                    ))}
                </div>
            ),
        },
        {
            name: 'face only',
            render: () => <TemariMascot pose="chill" size={40} faceOnly />,
        },
        { name: 'draw-in', render: () => <DrawIn /> },
    ],
} satisfies CatalogueEntry;
