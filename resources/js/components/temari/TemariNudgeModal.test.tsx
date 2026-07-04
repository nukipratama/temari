import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import TemariNudgeModal from './TemariNudgeModal';

const baseProps = {
    title: 'Judul nudge',
    body: 'Isi pesan yang ramah.',
    primaryLabel: 'Lakuin',
    onPrimary: vi.fn(),
};

describe('TemariNudgeModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<TemariNudgeModal open={false} onClose={vi.fn()} {...baseProps} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the title, body, primary CTA, and default dismiss label', () => {
        render(<TemariNudgeModal open onClose={vi.fn()} {...baseProps} />);
        expect(screen.getByText('Judul nudge')).toBeInTheDocument();
        expect(screen.getByText('Isi pesan yang ramah.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Lakuin' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Nanti aja' })).toBeInTheDocument();
    });

    it('wires the dialog to the title via aria-labelledby', () => {
        render(<TemariNudgeModal open onClose={vi.fn()} {...baseProps} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('aria-labelledby', 'temari-nudge-title');
        expect(document.getElementById('temari-nudge-title')).toHaveTextContent('Judul nudge');
    });

    it('honors a custom secondary label', () => {
        render(<TemariNudgeModal open onClose={vi.fn()} {...baseProps} secondaryLabel="Batal" />);
        expect(screen.getByRole('button', { name: 'Batal' })).toBeInTheDocument();
    });

    it('calls onPrimary when the primary CTA is clicked', () => {
        const onPrimary = vi.fn();
        render(<TemariNudgeModal open onClose={vi.fn()} {...baseProps} onPrimary={onPrimary} />);
        fireEvent.click(screen.getByRole('button', { name: 'Lakuin' }));
        expect(onPrimary).toHaveBeenCalledOnce();
    });

    it('calls onClose from both the dismiss CTA and the top-left close button', () => {
        const onClose = vi.fn();
        render(<TemariNudgeModal open onClose={onClose} {...baseProps} />);
        fireEvent.click(screen.getByRole('button', { name: 'Nanti aja' }));
        fireEvent.click(screen.getByLabelText('Tutup'));
        expect(onClose).toHaveBeenCalledTimes(2);
    });
});
