import type { ReactNode } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';

interface PlanHeadingProps {
    action: ReactNode;
}

export default function PlanHeading({ action }: Readonly<PlanHeadingProps>) {
    return (
        <>
            <Eyebrow token="hero" tone="ink-2">
                Plan
            </Eyebrow>
            <div className="mt-2 flex items-center justify-between gap-3">
                <h1 className="font-serif text-quote-lg text-foreground italic">
                    the weeks <em className="text-horizon-ink">ahead.</em>
                </h1>
                {action}
            </div>
        </>
    );
}
