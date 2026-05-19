import { cn } from '@/lib/cn';

interface DecorativeBlurProps {
    className: string;
    intensity?: 'md' | 'lg';
}

const INTENSITY: Record<NonNullable<DecorativeBlurProps['intensity']>, string> = {
    md: 'blur-2xl',
    lg: 'blur-3xl',
};

export default function DecorativeBlur({ className, intensity = 'lg' }: Readonly<DecorativeBlurProps>) {
    return (
        <span
            aria-hidden
            className={cn('pointer-events-none absolute rounded-full', INTENSITY[intensity], className)}
        />
    );
}
