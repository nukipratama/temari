import { describe, expect, it } from 'vitest';

import { reloadDocument } from './reloadDocument';

describe('reloadDocument', () => {
    it('asks the document to reload', () => {
        expect(() => reloadDocument()).not.toThrow();
    });
});
