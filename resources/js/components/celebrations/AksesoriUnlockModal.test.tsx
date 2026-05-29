import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AksesoriUnlockModal from './AksesoriUnlockModal';

const epikUnlock = {
    unlock_key: 'accessory.headband_epik',
    name: 'Headband Epik',
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
        expect(screen.getByText(/Headband Epik/)).toBeInTheDocument();
    });

    it('renders the criteria label for known keys', () => {
        render(<AksesoriUnlockModal unlock={epikUnlock} onClose={vi.fn()} />);
        expect(screen.getByText(/3 kartu Luar Biasa/)).toBeInTheDocument();
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
