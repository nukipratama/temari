import TemariMascot, {
    type MascotPose,
} from '@/components/temari/TemariMascot';

const PEEK_SIZE = 96;

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
    onSky?: boolean;
}

/**
 * Temari leaning in from a card's top-left corner, cropped by its edge. The
 * card must be `relative overflow-hidden` and keep its content clear of it.
 */
export default function MascotPeek({
    pose,
    onSky = false,
}: Readonly<MascotPeekProps>) {
    return (
        <TemariMascot
            pose={pose}
            size={PEEK_SIZE}
            onSky={onSky}
            className="pointer-events-none absolute -top-7.5 -left-7.5"
        />
    );
}
