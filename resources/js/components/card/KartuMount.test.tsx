import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KartuMount from './KartuMount';

describe('KartuMount', () => {
    it('renders its children inside the mount', () => {
        render(
            <KartuMount>
                <div>Kartu content</div>
            </KartuMount>,
        );
        expect(screen.getByText('Kartu content')).toBeInTheDocument();
    });
});
