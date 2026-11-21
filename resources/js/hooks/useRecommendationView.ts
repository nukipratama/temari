import { router } from '@inertiajs/react';
import { useEffect, type RefObject } from 'react';

import { postJson } from '@/lib/http';

const reloadedForStale = new Set<string>();

export function useRecommendationView(
    ref: RefObject<HTMLElement | null>,
    token: string | null | undefined,
) {
    useEffect(() => {
        const element = ref.current;
        if (!element || !token || typeof IntersectionObserver === 'undefined') {
            return;
        }
        let intersects = false;
        let sent = false;
        const observationId = crypto.randomUUID();
        const record = () => {
            if (!intersects || document.visibilityState !== 'visible' || sent) {
                return;
            }
            sent = true;
            void postJson('/plan/recommendations/shown', {
                token,
                observation_id: observationId,
            })
                .then((response) => {
                    if (
                        response.status === 409 &&
                        !reloadedForStale.has(token)
                    ) {
                        reloadedForStale.add(token);
                        router.reload();
                    }
                })
                .catch(() => undefined);
        };
        const observer = new IntersectionObserver((entries) => {
            intersects = entries.some((entry) => entry.isIntersecting);
            record();
        });
        observer.observe(element);
        document.addEventListener('visibilitychange', record);
        return () => {
            observer.disconnect();
            document.removeEventListener('visibilitychange', record);
        };
    }, [ref, token]);
}
