import { render, screen } from '@testing-library/react';
import { Hash } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import DevtoolsHeader from './DevtoolsHeader';

describe('DevtoolsHeader', () => {
    it('titles the page and links back to the devtools index', () => {
        render(
            <DevtoolsHeader title="narration">what it costs</DevtoolsHeader>,
        );

        expect(
            screen.getByRole('heading', { level: 1, name: 'narration' }),
        ).toBeInTheDocument();
        expect(screen.getByText('what it costs')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Temari · Devtools' }),
        ).toHaveAttribute('href', '/devtools');
    });

    it('draws the icon tile only when given an icon', () => {
        const { container, rerender } = render(
            <DevtoolsHeader title="narration" />,
        );
        expect(container.querySelector('.bg-leaf-deep')).toBeNull();

        rerender(<DevtoolsHeader title="narration" icon={Hash} />);
        expect(container.querySelector('.bg-leaf-deep')).not.toBeNull();
    });
});
