import { cn } from '@/lib/cn';

export type MeshVariant = 'dawn' | 'night' | 'ember';

interface MeshBackdropProps {
    variant?: MeshVariant;
    className?: string;
}

interface Blob {
    pos: string;
    size: string;
    color: string;
}

// Tuned for the pale sage page surface. Blobs sit at moderate alpha so
// they suggest atmosphere without dominating content. Used primarily on
// the login page (atmospheric moment); in-app pages stay clean.
const VARIANTS: Record<MeshVariant, Blob[]> = {
    dawn: [
        { pos: 'top-[-20%] left-[-10%]', size: 'h-[60%] w-[70%]', color: 'bg-[radial-gradient(circle,_#D9B23A55_0%,_transparent_60%)]' },
        { pos: 'bottom-[-20%] right-[-10%]', size: 'h-[60%] w-[70%]', color: 'bg-[radial-gradient(circle,_#C4623F55_0%,_transparent_65%)]' },
        { pos: 'top-[40%] right-[30%]', size: 'h-[40%] w-[40%]', color: 'bg-[radial-gradient(circle,_#6B8E6F33_0%,_transparent_70%)]' },
    ],
    night: [
        { pos: 'top-[-20%] left-[-10%]', size: 'h-[60%] w-[70%]', color: 'bg-[radial-gradient(circle,_#6B8E6F55_0%,_transparent_65%)]' },
        { pos: 'bottom-[-20%] right-[-10%]', size: 'h-[60%] w-[70%]', color: 'bg-[radial-gradient(circle,_#7B5BB655_0%,_transparent_65%)]' },
        { pos: 'top-[30%] left-[40%]', size: 'h-[40%] w-[40%]', color: 'bg-[radial-gradient(circle,_#C4623F44_0%,_transparent_70%)]' },
    ],
    ember: [
        { pos: 'top-[-10%] left-[20%]', size: 'h-[70%] w-[60%]', color: 'bg-[radial-gradient(circle,_#D9B23A77_0%,_transparent_60%)]' },
        { pos: 'bottom-[-20%] right-[10%]', size: 'h-[60%] w-[60%]', color: 'bg-[radial-gradient(circle,_#C4623F66_0%,_transparent_65%)]' },
        { pos: 'top-[50%] left-[-10%]', size: 'h-[40%] w-[40%]', color: 'bg-[radial-gradient(circle,_#B8941E44_0%,_transparent_70%)]' },
    ],
};

// Painterly atmospheric backdrop made of 3 large, blurred radial blobs.
// Absolute-positioned inside its parent; the parent should be `relative`
// with `overflow-hidden`. Static — no animation, no reduced-motion gate.
export default function MeshBackdrop({ variant = 'dawn', className }: Readonly<MeshBackdropProps>) {
    return (
        <div aria-hidden className={cn('pointer-events-none absolute inset-0 overflow-hidden', className)}>
            {VARIANTS[variant].map((blob) => (
                <div
                    key={blob.pos}
                    className={cn('absolute rounded-full opacity-70 blur-3xl', blob.pos, blob.size, blob.color)}
                />
            ))}
        </div>
    );
}
