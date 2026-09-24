import TemariMascot, {
    type MascotPose,
} from '@/components/temari/TemariMascot';
import { cn } from '@/lib/cn';

interface MascotWatermarkProps {
    pose: MascotPose;
    /** Where it sits: whichever edge the card's own content leaves open. */
    className: string;
}

/**
 * Temari as a faint 200px watermark bleeding off a card's edge, behind the
 * content. The card must be `relative isolate overflow-hidden`.
 */
export default function MascotWatermark({
    pose,
    className,
}: Readonly<MascotWatermarkProps>) {
    return (
        <TemariMascot
            pose={pose}
            size={200}
            className={cn(
                'pointer-events-none absolute -z-10 opacity-16 dark:opacity-20',
                className,
            )}
        />
    );
}
