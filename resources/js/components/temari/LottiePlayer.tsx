import Lottie from 'lottie-react';

/**
 * Thin wrapper isolated in its own module so `lottie-react` (and the
 * underlying `lottie-web` engine) only enters the bundle when something
 * actually code-splits to it via `lazy(() => import(...))`.
 */
export default function LottiePlayer({ animationData }: Readonly<{ animationData: unknown }>) {
    return <Lottie animationData={animationData} loop className="h-full w-full" />;
}
