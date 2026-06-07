import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AksesoriUnlockModal from './AksesoriUnlockModal';

const epikUnlock = {
    unlock_key: 'accessory.ikat_kepala_epik',
    name: 'Ikat Kepala Luar Biasa',
    icon: 'mdi:star',
    is_major: true,
};

const minorUnlock = {
    unlock_key: 'accessory.some_minor',
    name: 'Minor Thing',
    icon: 'mdi:gift',
    is_major: false,
};

describe('AksesoriUnlockModal', () => {
    it('renders nothing when unlock is null', () => {
        const { container } = render(<AksesoriUnlockModal unlock={null} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when unlock is not major', () => {
        const { container } = render(<AksesoriUnlockModal unlock={minorUnlock} onClose={vi.fn()} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders the unlock name for a major unlock', () => {
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        expect(screen.getByText(/Ikat Kepala Luar Biasa/)).toBeInTheDocument();
    });

    it('exposes a labelled modal dialog', () => {
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('aria-labelledby', 'aksesori-unlock-title');
        expect(document.getElementById('aksesori-unlock-title')).toBeInTheDocument();
    });

    it('closes on the Escape key', () => {
        const onClose = vi.fn();
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={onClose} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('moves focus into the dialog when it opens', () => {
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        const dialog = screen.getByRole('dialog');
        expect(dialog.contains(document.activeElement)).toBe(true);
    });

    it('calls onClose when "Nanti aja" is clicked', () => {
        const onClose = vi.fn();
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={onClose} />);
        fireEvent.click(screen.getByText('Nanti aja'));
        expect(onClose).toHaveBeenCalledOnce();
    });

    it('renders the "Pakai sekarang" button for the equip action', () => {
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        expect(screen.getByText('Pakai sekarang')).toBeInTheDocument();
    });

    it('calls onClose when "Pakai sekarang" is clicked', () => {
        const onClose = vi.fn();
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={onClose} />);
        fireEvent.click(screen.getByText('Pakai sekarang'));
        expect(onClose).toHaveBeenCalledOnce();
    });
});
