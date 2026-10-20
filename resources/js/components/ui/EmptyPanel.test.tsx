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
            <EmptyPanel title="No data yet" className="" />,
        );
        expect(screen.getByText('No data yet')).toBeInTheDocument();
        expect(container.firstElementChild).toHaveClass('border-border-strong');
        expect(container.firstElementChild).toHaveClass('shadow-e1');
        // The prototype draws no dashed border anywhere; its empty cards are
        // ordinary cards on the heavier border.
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
        render(<EmptyPanel title="Title only" className="" />);
        expect(screen.getByText('Title only')).toBeInTheDocument();
    });

    it('renders the body copy when given', () => {
        render(
            <EmptyPanel
                title="Title"
                body="Sub-copy goes here."
                className=""
            />,
        );
        expect(screen.getByText('Sub-copy goes here.')).toBeInTheDocument();
    });

    it("renders Temari's face when the caller asks for one", () => {
        const { container } = render(
            <EmptyPanel face title="Title" className="" />,
        );
        expect(container.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'sleepy',
        );
    });

    it('omits the face by default', () => {
        const { container } = render(<EmptyPanel title="Title" className="" />);
        expect(container.querySelector('svg')).not.toBeInTheDocument();
    });

    it('renders the composed action node', () => {
        render(
            <EmptyPanel
                title="Title"
                action={<button type="button">Aksi</button>}
                className=""
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Aksi' }),
        ).toBeInTheDocument();
    });

    it('renders the title and body in the canonical typography for every site', () => {
        render(<EmptyPanel title="Title" body="Sub-copy" className="" />);
        expect(screen.getByText('Title')).toHaveClass(
            'text-2xl',
            'text-text-2',
        );
        expect(screen.getByText('Sub-copy')).toHaveClass(
            'text-sm',
            'text-text-2',
        );
    });

    it('dozes inline with a centred title rather than stacked above it', () => {
        const { container } = render(
            <EmptyPanel face title="Title" className="" />,
        );
        const title = screen.getByText('Title');

        expect(title.querySelector('svg[data-mascot]')).toHaveAttribute(
            'width',
            '36',
        );
        expect(title).toHaveClass('inline-flex');
        expect(container.querySelectorAll('svg[data-mascot]')).toHaveLength(1);
    });

    it('lays the face beside the copy when the layout is horizontal', () => {
        const { container } = render(
            <EmptyPanel
                face
                layout="horizontal"
                title="Title"
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
        // Temari is a sibling of the copy block, not inside the title.
        expect(screen.getByText('Title').querySelector('svg')).toBeNull();
        expect(
            container.firstElementChild?.querySelector(':scope > svg'),
        ).toHaveAttribute('width', '40');
    });

    it('renders as a div by default', () => {
        const { container } = render(<EmptyPanel title="Title" />);
        expect(container.firstElementChild?.tagName).toBe('DIV');
    });

    it('renders as the given landmark element when as is set', () => {
        const { container } = render(<EmptyPanel title="Title" as="section" />);
        expect(container.firstElementChild?.tagName).toBe('SECTION');
    });
});
