import { Link } from '@inertiajs/react';

import FaceIcon from '@/components/temari/FaceIcon';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';

/**
 * The prototype's `planState: 'empty'` branch — what Today leads with before
 * the periodizer has a season to draw from. The shipped page used to render
 * nothing at all in this slot.
 */
export default function NoPlanCard() {
    return (
        <Card as="section" className="flex items-center gap-3.5">
            <FaceIcon size={40} />
            <div>
                <p className="font-serif text-base font-semibold italic text-foreground">
                    No plan yet.
                </p>
                <p className="mt-1 mb-2.5 text-xs leading-relaxed text-foreground">
                    Set one up and Temari will lay out the weeks ahead.
                </p>
                <Link
                    href="/plan"
                    className="focus-ring inline-flex items-center gap-1 rounded text-[0.71875rem] font-bold text-icon-accent"
                >
                    Set up a plan
                    <Icon
                        icon="mdi:arrow-right"
                        width={12}
                        height={12}
                        aria-hidden
                    />
                </Link>
            </div>
        </Card>
    );
}
