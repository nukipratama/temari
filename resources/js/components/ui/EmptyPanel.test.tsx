import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import EmptyPanel from './EmptyPanel';
import { setMockPage } from '@/test/setup';

beforeEach(() => {
    setMockPage({
        auth: { user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null } },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('EmptyPanel', () => {
    it('renders the title inside the dashed placeholder panel', () => {
        const { container } = render(<EmptyPanel title="Belum ada data" className="" />);
        expect(screen.getByText('Belum ada data')).toBeInTheDocument();
        expect(container.firstElementChild).toHaveClass('border-dashed');
        expect(container.firstElementChild).toHaveClass('border-cream-deep');
        expect(container.firstElementChild).not.toHaveClass('border-2');
    });

    it('lets a caller override the panel padding via className', () => {
        const { container } = render(<EmptyPanel title="x" className="py-10" />);
        expect(container.firstElementChild).toHaveClass('py-10');
    });

    it('omits the body paragraph when none is given', () => {
        render(<EmptyPanel title="Cuma judul" className="" />);
        expect(screen.getByText('Cuma judul')).toBeInTheDocument();
    });

    it('renders the body copy when given', () => {
        render(<EmptyPanel title="Judul" body="Sub-copy di sini." className="" />);
        expect(screen.getByText('Sub-copy di sini.')).toBeInTheDocument();
    });

    it('renders the Temari mascot when a pose is given', () => {
        const { container } = render(<EmptyPanel pose="excited" title="Judul" className="" />);
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    it('omits the mascot when no pose is given', () => {
        const { container } = render(<EmptyPanel title="Judul" className="" />);
        expect(container.querySelector('svg')).not.toBeInTheDocument();
    });

    it('renders the composed action node', () => {
        render(<EmptyPanel title="Judul" action={<button type="button">Aksi</button>} className="" />);
        expect(screen.getByRole('button', { name: 'Aksi' })).toBeInTheDocument();
    });

    it('lets a caller override title and body typography', () => {
        render(
            <EmptyPanel
                title="Judul"
                body="Sub-copy"
                titleClassName="text-base text-ink-3"
                bodyClassName="text-ink-3"
                className=""
            />,
        );
        expect(screen.getByText('Judul')).toHaveClass('text-base', 'text-ink-3');
        expect(screen.getByText('Sub-copy')).toHaveClass('text-ink-3');
    });
});
