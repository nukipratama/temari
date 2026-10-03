import { Children, Fragment, isValidElement, type ReactNode } from 'react';

import { cn } from '@/lib/cn';

interface LaneStackProps {
    children: ReactNode;
    className?: string;
}

const STACK =
    'flex flex-col gap-6 [&>:empty]:hidden [&>:not(:empty)~:not(:empty)]:border-t [&>:not(:empty)~:not(:empty)]:border-dashed [&>:not(:empty)~:not(:empty)]:border-border [&>:not(:empty)~:not(:empty)]:pt-6';

function lane(child: ReactNode): ReactNode {
    if (child === null) {
        return null;
    }

    if (
        isValidElement<{ children?: ReactNode }>(child) &&
        child.type === Fragment
    ) {
        // eslint-disable-next-line @eslint-react/no-children-map
        return Children.map(child.props.children, lane);
    }

    return <div data-slot="lane">{child}</div>;
}

/** MASTER.md's lane stack: each section in its own slot, split by a dashed hairline drawn on the slot. */
export default function LaneStack({
    children,
    className,
}: Readonly<LaneStackProps>) {
    return (
        <div className={cn(STACK, className)}>
            {/* eslint-disable-next-line @eslint-react/no-children-map */}
            {Children.map(children, lane)}
        </div>
    );
}
