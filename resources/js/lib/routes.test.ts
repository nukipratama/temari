import { describe, expect, it } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import { aktivitasUrl, analysisTriggerUrl } from './routes';

describe('aktivitasUrl', () => {
    it('reads activity_id from a row that carries it', () => {
        expect(aktivitasUrl({ activity_id: 42 })).toBe('/aktivitas/42');
    });

    it('reads id from an Activity', () => {
        expect(aktivitasUrl({ id: 99 })).toBe('/aktivitas/99');
    });
});

describe('analysisTriggerUrl', () => {
    it('omits the query string when there is no discriminator', () => {
        expect(
            analysisTriggerUrl({
                type: 'briefing_mascot_voice',
                subject_id: 7,
                discriminator: null,
            }),
        ).toBe('/api/analyses/briefing_mascot_voice/7/trigger');
    });

    it('appends the discriminator as an encoded query parameter', () => {
        expect(
            analysisTriggerUrl({
                type: 'weekly_recap',
                subject_id: 3,
                discriminator: '2026-05-19',
            }),
        ).toBe('/api/analyses/weekly_recap/3/trigger?discriminator=2026-05-19');
    });

    it('percent-encodes a discriminator carrying url-significant characters', () => {
        expect(
            analysisTriggerUrl({
                type: 'briefing_mascot_voice',
                subject_id: 1,
                discriminator: 'a b&c=d/e',
            }),
        ).toBe(
            '/api/analyses/briefing_mascot_voice/1/trigger?discriminator=a%20b%26c%3Dd%2Fe',
        );
    });

    it('treats an empty discriminator as absent', () => {
        expect(
            analysisTriggerUrl({
                type: 'briefing_mascot_voice',
                subject_id: 5,
                discriminator: '',
            }),
        ).toBe('/api/analyses/briefing_mascot_voice/5/trigger');
    });

    it('addresses the subject, never the analysis row id', () => {
        const analysis: AnalysisPayload = {
            id: 999,
            status: 'done',
            content: null,
            type: 'briefing_mascot_voice',
            subject_type: 'App\\Models\\Activity',
            subject_id: 12,
            discriminator: null,
        };

        expect(analysisTriggerUrl(analysis)).toBe(
            '/api/analyses/briefing_mascot_voice/12/trigger',
        );
    });
});
