import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import type { WeeklySnapshot } from '@/types/inertia';

import FaceIcon from '@/components/temari/FaceIcon';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';

/**
 * The prototype's `planState: 'empty'` branch — what Today leads with before
 * the periodizer has a season to draw from. The shipped page used to render
 * nothing at all in this slot. The week's own numbers ride along underneath,
 * since the merged plan card that normally carries them is not drawn here.
 */
export default function NoPlanCard({
    snapshot = null,
}: Readonly<{ snapshot?: WeeklySnapshot | null }>) {
    const km = snapshot?.distance_km ?? null;
    const trimp = snapshot?.weekly_trimp ?? null;

    return (
        <Card as="section">
            <div className="flex items-center gap-3.5">
                <FaceIcon size={40} />
                <div>
                    <p className="text-base font-semibold text-foreground">
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
                            icon={ArrowRight}
                            width={12}
                            height={12}
                            aria-hidden
                        />
                    </Link>
                </div>
            </div>
            <p className="mt-3.5 border-t border-border pt-3 font-mono text-[0.625rem] uppercase tracking-[0.05em] text-foreground">
                this week · {km === null ? '—' : km.toFixed(1)} km ·{' '}
                {trimp === null ? '—' : Math.round(trimp)} trimp
            </p>
        </Card>
    );
}
