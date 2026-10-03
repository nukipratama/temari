import { describe, expect, it } from 'vitest';

import { PR_CATEGORY_LABELS } from './pr';

describe('PR_CATEGORY_LABELS', () => {
    it('maps the canonical distance + effort categories to display labels', () => {
        expect(PR_CATEGORY_LABELS['5km']).toBe('5 km');
        expect(PR_CATEGORY_LABELS.half_marathon).toBe('Half Marathon');
        expect(PR_CATEGORY_LABELS.best_20min).toBe('Best 20 min');
        expect(PR_CATEGORY_LABELS.best_30min).toBe('Best 30 min');
    });
});
