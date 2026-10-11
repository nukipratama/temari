import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';

export default function RaceHeading() {
    return (
        <>
            <Eyebrow token="hero" tone="ink-2">
                Race
            </Eyebrow>
            <div className="mt-2 flex items-center justify-between gap-3">
                <h1 className="font-serif text-quote-lg text-foreground italic">
                    your <em className="text-horizon-ink">race.</em>
                </h1>
                <Link
                    href="/plan"
                    className="focus-ring hit-area inline-flex flex-none items-center gap-0.5 text-xs font-semibold text-horizon-ink"
                >
                    plan
                    <Icon icon={ArrowRight} className="size-3" aria-hidden />
                </Link>
            </div>
        </>
    );
}
