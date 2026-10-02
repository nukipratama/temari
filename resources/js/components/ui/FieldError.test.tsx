import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FieldError from './FieldError';

describe('FieldError', () => {
    it.each([undefined, null, ''])('renders nothing for %j', (message) => {
        const { container } = render(<FieldError message={message} />);
        expect(container.firstChild).toBeNull();
    });

    it('announces the message as an alert', () => {
        render(<FieldError message="Race day has to be in the future." />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Race day has to be in the future.',
        );
    });
});
