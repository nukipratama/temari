import type { PendingVisit } from '@inertiajs/core';

import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** True while a partial visit that reloads every one of `props` is in flight. */
export default function useMonthReload(props: ReadonlyArray<string>): boolean {
    const [reloading, setReloading] = useState(false);

    useEffect(() => {
        const reloadsMonth = (visit: PendingVisit) =>
            props.every((prop) => visit.only.includes(prop));
        const offStart = router.on('start', (event) => {
            if (reloadsMonth(event.detail.visit)) {
                setReloading(true);
            }
        });
        const offFinish = router.on('finish', (event) => {
            if (reloadsMonth(event.detail.visit)) {
                setReloading(false);
            }
        });

        return () => {
            offStart();
            offFinish();
        };
    }, [props]);

    return reloading;
}
