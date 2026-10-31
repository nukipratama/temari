import { useEffect, useState } from 'react';

import { getJson } from '@/lib/http';

interface TokenDay {
    date: string;
    recommendation_token?: string;
}

const OPTIMISTIC_FIELDS = new Set<PropertyKey>([
    'skipped',
    'pinned',
    'actions',
]);

function sameServerDay<T extends TokenDay>(fetchedFor: T, day: T): boolean {
    return (Object.keys(day) as (keyof T)[]).every(
        (key) => OPTIMISTIC_FIELDS.has(key) || fetchedFor[key] === day[key],
    );
}

export function useDayRecommendationToken<T extends TokenDay>(
    day: T | null,
): string | undefined {
    const [fetched, setFetched] = useState<{
        day: T;
        token: string;
    } | null>(null);
    const fetchedToken =
        fetched !== null && day !== null && sameServerDay(fetched.day, day)
            ? fetched.token
            : undefined;
    const needsFetch =
        day !== null &&
        day.recommendation_token === undefined &&
        fetchedToken === undefined;

    useEffect(() => {
        if (!day || !needsFetch) {
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
    }, [day, needsFetch]);

    return day?.recommendation_token ?? fetchedToken;
}
