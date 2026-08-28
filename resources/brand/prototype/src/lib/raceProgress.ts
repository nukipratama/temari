export type Confidence = 'low' | 'medium' | 'high';

export const CONFIDENCE_COPY: Record<Confidence, string> = {
    low: 'wide range, thin pr sample',
    medium: 'moderate range',
    high: 'narrow range, well-fitted',
};

export type Projection = {
    distanceKm: number;
    lowSec: number;
    predictedSec: number;
    highSec: number;
    confidence: Confidence;
    prCount: number;
};

// Mock Riegel-projection output for the current mock race (half marathon) —
// stands in for a real regression across the athlete's own PRs.
export const MOCK_PROJECTION: Projection = {
    distanceKm: 21.1,
    lowSec: 6615,
    predictedSec: 6750,
    highSec: 6940,
    confidence: 'medium',
    prCount: 3,
};

export const MOCK_RACE = {
    name: 'jakarta half marathon',
    date: '12 oct 2026',
    daysToGo: 42,
    distanceKm: 21.1,
    goalTimeSec: 6600,
};
