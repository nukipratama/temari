import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import EmptyPanel from './EmptyPanel';

beforeEach(() => {
    setMockPage({
        auth: {
            user: { id: 1, name: 'Ada', first_name: 'Ada', avatar_url: null },
        },
        flash: {},
        demoLoginEnabled: false,
    });
});

describe('EmptyPanel', () => {
    it('renders the title inside a solid card, as the prototype draws it', () => {
        const { container } = render(
            <EmptyPanel title="Belum ada data" className="" />,
        );
        expect(screen.getByText('Belum ada data')).toBeInTheDocument();
        expect(container.firstElementChild).toHaveClass('border-border-strong');
        expect(container.firstElementChild).toHaveClass('shadow-e1');
        // The prototype draws no dashed border anywhere; its empty cards are
        // ordinary cards on the heavier border. See T3.
        expect(container.firstElementChild).not.toHaveClass('border-dashed');
        expect(container.firstElementChild).not.toHaveClass('border-2');
    });

    it('lets a caller override the panel padding via className', () => {
        const { container } = render(
            <EmptyPanel title="x" className="py-10" />,
        );
        expect(container.firstElementChild).toHaveClass('py-10');
    });

    it('omits the body paragraph when none is given', () => {
        render(<EmptyPanel title="Cuma judul" className="" />);
        expect(screen.getByText('Cuma judul')).toBeInTheDocument();
    });

    it('renders the body copy when given', () => {
        render(
            <EmptyPanel title="Judul" body="Sub-copy di sini." className="" />,
        );
        expect(screen.getByText('Sub-copy di sini.')).toBeInTheDocument();
    });

    it("renders Temari's face when the caller asks for one", () => {
        const { container } = render(
            <EmptyPanel face title="Judul" className="" />,
        );
        expect(container.querySelector('[data-face-icon]')).toBeInTheDocument();
    });

    it('omits the face by default', () => {
        const { container } = render(<EmptyPanel title="Judul" className="" />);
        expect(container.querySelector('svg')).not.toBeInTheDocument();
    });

    it('renders the composed action node', () => {
        render(
            <EmptyPanel
                title="Judul"
                action={<button type="button">Aksi</button>}
                className=""
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Aksi' }),
        ).toBeInTheDocument();
    });

    it('renders the title and body in the canonical typography for every site', () => {
        render(<EmptyPanel title="Judul" body="Sub-copy" className="" />);
        expect(screen.getByText('Judul')).toHaveClass(
            'text-2xl',
            'text-text-2',
        );
        expect(screen.getByText('Sub-copy')).toHaveClass(
            'text-sm',
            'text-text-2',
        );
    });

    it('draws the face at 40 by default and at 48 only when the caller asks', () => {
        const { container, rerender } = render(
            <EmptyPanel face title="Judul" className="" />,
        );
        expect(container.querySelector('[data-face-icon]')).toHaveAttribute(
            'width',
            '40',
        );

        rerender(<EmptyPanel face faceSize={48} title="Judul" className="" />);
        expect(container.querySelector('[data-face-icon]')).toHaveAttribute(
            'width',
            '48',
        );
    });

    it('lays the face beside the copy when the layout is horizontal', () => {
        const { container } = render(
            <EmptyPanel
                face
                layout="horizontal"
                title="Judul"
                body="Sub-copy"
                className=""
            />,
        );
        expect(container.firstElementChild).toHaveClass(
            'flex',
            'items-center',
            'text-left',
        );
        expect(container.firstElementChild).not.toHaveClass('text-center');
        // The face is a sibling of the copy block, not stacked above it.
        expect(screen.getByText('Judul')).not.toHaveClass('mt-4');
    });

    it('renders as a div by default', () => {
        const { container } = render(<EmptyPanel title="Judul" />);
        expect(container.firstElementChild?.tagName).toBe('DIV');
    });

    it('renders as the given landmark element when as is set', () => {
        const { container } = render(<EmptyPanel title="Judul" as="section" />);
        expect(container.firstElementChild?.tagName).toBe('SECTION');
    });
});
