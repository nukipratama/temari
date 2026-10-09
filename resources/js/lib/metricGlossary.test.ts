import { describe, expect, it } from 'vitest';

import { METRIC_GLOSSARY } from './metricGlossary';

const BANNED =
    /injury|sore|fitness|readiness|overreach|adaptation|breaking down/i;

describe('METRIC_GLOSSARY', () => {
    it.each(Object.entries(METRIC_GLOSSARY))(
        'keeps physiology wording out of %s',
        (_key, entry) => {
            expect(entry.body).not.toMatch(BANNED);
        },
    );

    it('names the load numbers long-term load, short-term load and load balance', () => {
        expect(METRIC_GLOSSARY.ctl.label).toBe('long-term load');
        expect(METRIC_GLOSSARY.atl.label).toBe('short-term load');
        expect(METRIC_GLOSSARY.form.label).toBe('load balance');
    });

    it('has three load balance states and no legacy four', () => {
        expect(METRIC_GLOSSARY.status_fresh.label).toBe('fresh');
        expect(METRIC_GLOSSARY.status_steady.label).toBe('steady');
        expect(METRIC_GLOSSARY.status_heavy.label).toBe('heavy');
        expect(METRIC_GLOSSARY).not.toHaveProperty('status_optimal');
        expect(METRIC_GLOSSARY).not.toHaveProperty('status_fatigued');
        expect(METRIC_GLOSSARY).not.toHaveProperty('status_overreaching');
    });

    it('points a heavy balance at other causes', () => {
        expect(METRIC_GLOSSARY.status_heavy.body).toContain('illness');
        expect(METRIC_GLOSSARY.status_heavy.body).toContain('under-fuelling');
        expect(METRIC_GLOSSARY.status_heavy.body).not.toContain('how you feel');
    });
});
