import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import VersionDiff from './VersionDiff';

describe('VersionDiff', () => {
    it('marks what the rewrite dropped and what it added', () => {
        render(
            <VersionDiff
                before="you ran slow today"
                after="you ran fast today"
            />,
        );

        expect(screen.getByText('previous vs current')).toBeInTheDocument();
        expect(screen.getByText('slow').className).toContain('line-through');
        expect(screen.getByText('fast').className).toContain('text-leaf-ink');
    });
});
