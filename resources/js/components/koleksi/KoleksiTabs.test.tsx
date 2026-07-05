import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import KoleksiTabs from './KoleksiTabs';

describe('KoleksiTabs', () => {
    it('renders all four sub-tab labels', () => {
        render(<KoleksiTabs active="kartu" />);
        expect(screen.getByText('Kartu')).toBeInTheDocument();
        expect(screen.getByText('Rekor')).toBeInTheDocument();
        expect(screen.getByText('Aksesori')).toBeInTheDocument();
        expect(screen.getByText('Target')).toBeInTheDocument();
    });

    it('marks only the active tab with aria-current', () => {
        render(<KoleksiTabs active="aksesori" />);
        expect(screen.getByText('Aksesori').closest('a')).toHaveAttribute('aria-current', 'page');
        expect(screen.getByText('Kartu').closest('a')).not.toHaveAttribute('aria-current');
    });

    it('shows the count chip only on the active tab when given', () => {
        render(<KoleksiTabs active="target" activeCount="3" />);
        expect(screen.getByText('3')).toBeInTheDocument();
        expect(screen.getByText('Target').closest('a')).toHaveTextContent('3');
        expect(screen.getByText('Kartu').closest('a')).not.toHaveTextContent('3');
    });

    it('renders no count chip when activeCount is omitted', () => {
        render(<KoleksiTabs active="kartu" />);
        expect(screen.getByText('Kartu').closest('a')!.querySelector('.bg-horizon\\/25')).toBeNull();
    });
});
