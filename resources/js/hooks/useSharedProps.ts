import { usePage } from '@inertiajs/react';
import { createContext, use } from 'react';

import type { SharedProps } from '@/types/inertia';

/** Overrides the page's shared props for a subtree; the design catalogue sets it per example. */
export const SharedPropsOverrideContext = createContext<Partial<SharedProps>>(
    {},
);

export function useSharedProps(): SharedProps {
    const props = usePage<SharedProps>().props;
    const override = use(SharedPropsOverrideContext);

    return { ...props, ...override };
}
