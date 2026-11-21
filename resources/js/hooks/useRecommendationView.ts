import { router } from '@inertiajs/react';
import { useEffect, type RefObject } from 'react';

import { postJson } from '@/lib/http';

const STALE_RELOAD_COOLDOWN_MS = 60_000;
let lastStaleReloadAt = -Infinity;

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
                        Date.now() - lastStaleReloadAt >=
                            STALE_RELOAD_COOLDOWN_MS
                    ) {
                        lastStaleReloadAt = Date.now();
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
