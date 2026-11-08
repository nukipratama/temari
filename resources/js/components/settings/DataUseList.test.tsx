import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import DataUseList from './DataUseList';

describe('DataUseList', () => {
    it('renders each point the server hands it as a list item', () => {
        render(
            <DataUseList
                points={['Inference only, never training.', 'Second point.']}
            />,
        );

        expect(screen.getAllByRole('listitem')).toHaveLength(2);
        expect(
            screen.getByText('Inference only, never training.'),
        ).toBeInTheDocument();
    });
});
