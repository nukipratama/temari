import { Head, Link } from '@inertiajs/react';
import AppShell from '@/layouts/AppShell';
import BrandMark from '@/components/BrandMark';

/**
 * Public landing — currently unreachable (root redirects to /login or
 * /dashboard). Kept brand-led so a future "/" splash stays on-message:
 * pre-auth visitors don't yet know who Temari is.
 */
export default function Welcome() {
    return (
        <AppShell showHeader={false}>
            <Head title="TemanLari" />
            <main className="mx-auto flex min-h-screen max-w-2xl flex-col items-center justify-center px-6 py-12 text-center">
                <BrandMark tagline />
                <Link
                    href="/login"
                    className="mt-8 rounded-xl bg-brand-500 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-600"
                >
                    Mulai
                </Link>
            </main>
        </AppShell>
    );
}
