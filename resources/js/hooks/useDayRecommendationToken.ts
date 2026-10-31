import { useEffect, useState } from 'react';

import { getJson } from '@/lib/http';

interface TokenDay {
    date: string;
    recommendation_token?: string;
}

export function useDayRecommendationToken(
    day: TokenDay | null,
): string | undefined {
    const [fetched, setFetched] = useState<{
        day: TokenDay;
        token: string;
    } | null>(null);

    useEffect(() => {
        if (!day || day.recommendation_token !== undefined) {
            return;
        }
        let current = true;
        getJson(`/plan/days/${day.date}/recommendation`)
            .then((response) => (response.ok ? response.json() : null))
            .then((body: { token?: string } | null) => {
                if (current && body?.token) {
                    setFetched({ day, token: body.token });
                }
            })
            .catch(() => undefined);

        return () => {
            current = false;
        };
    }, [day]);

    return (
        day?.recommendation_token ??
        (fetched?.day === day ? fetched?.token : undefined)
    );
}
