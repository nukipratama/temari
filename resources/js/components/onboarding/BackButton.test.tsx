import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import BackButton from './BackButton';

describe('Onboarding/BackButton', () => {
    it('is labelled Back and fires its handler on click', () => {
        const onClick = vi.fn();
        render(<BackButton onClick={onClick} />);

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(onClick).toHaveBeenCalledOnce();
    });
});
