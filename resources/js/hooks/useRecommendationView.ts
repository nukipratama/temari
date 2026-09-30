import { useEffect, type RefObject } from 'react';

import { postJson } from '@/lib/http';

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
            }).catch(() => undefined);
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
