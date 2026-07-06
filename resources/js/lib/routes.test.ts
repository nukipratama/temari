import { describe, expect, it } from 'vitest';
import { aktivitasUrl } from './routes';

describe('aktivitasUrl', () => {
    it('reads activity_id from a row that carries it', () => {
        expect(aktivitasUrl({ activity_id: 42 })).toBe('/aktivitas/42');
    });

    it('reads id from an Activity', () => {
        expect(aktivitasUrl({ id: 99 })).toBe('/aktivitas/99');
    });
});
