import TemariMascot, {
    type MascotPose,
} from '@/components/temari/TemariMascot';
import { cn } from '@/lib/cn';

const PEEK = {
    panel: { size: 112, className: '-top-8.5 -left-8.5' },
    card: { size: 96, className: '-top-7.5 -left-7.5' },
} as const;

/** How far a peeking panel's header must clear the mascot. */
export const PANEL_PEEK_CLEARANCE = 'pl-15';

/**
 * A float that reserves a `card` peek's corner, so the copy beside it wraps
 * around the mascot instead of giving up a whole column.
 */
export function MascotPeekClearance() {
    return (
        <span
            aria-hidden
            data-testid="mascot-peek-clearance"
            className="float-left mr-2 h-14 w-13"
        />
    );
}

interface MascotPeekProps {
    pose: MascotPose;
    /** `panel` for the rounded-panel heroes, `card` for compact cards. */
    fit?: keyof typeof PEEK;
    onSky?: boolean;
}

/**
 * Temari leaning in from a card's top-left corner, cropped by its edge. The
 * card must be `relative overflow-hidden` and keep its content clear of it.
 */
export default function MascotPeek({
    pose,
    fit = 'card',
    onSky = false,
}: Readonly<MascotPeekProps>) {
    const { size, className } = PEEK[fit];

    return (
        <TemariMascot
            pose={pose}
            size={size}
            onSky={onSky}
            className={cn('pointer-events-none absolute', className)}
        />
    );
}
