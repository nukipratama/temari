import { Link, usePage } from "@inertiajs/react";
import { cn } from "@/lib/cn";
import BrandMark from "@/components/BrandMark";
import StravaSyncBadge from "@/components/StravaSyncBadge";
import UserMenu from "@/components/UserMenu";
import type { SharedProps } from "@/types/inertia";

type TabId = "hari-ini" | "koleksi" | "riwayat" | "aku";

interface NavItem {
  id: TabId;
  label: string;
  href: string;
  prefixes: ReadonlyArray<string>;
}

const ITEMS: ReadonlyArray<NavItem> = [
  { id: "hari-ini", label: "Hari Ini", href: "/", prefixes: ["/"] },
  {
    id: "koleksi",
    label: "Koleksi",
    href: "/kartu",
    prefixes: ["/koleksi", "/kartu", "/aksesori", "/rekor", "/target"],
  },
  {
    id: "riwayat",
    label: "Riwayat",
    href: "/aktivitas",
    prefixes: ["/riwayat", "/aktivitas", "/kalender"],
  },
  { id: "aku", label: "Aku", href: "/profil", prefixes: ["/aku", "/profil", "/pengaturan"] },
];

export function activeTabFromUrl(url: string): TabId | null {
  const path = url.split("?")[0];
  if (path === "/") return "hari-ini";
  for (const item of ITEMS) {
    if (item.id === "hari-ini") continue;
    if (item.prefixes.some((p) => path === p || path.startsWith(`${p}/`))) {
      return item.id;
    }
  }
  return null;
}

export default function TopNav() {
  const { url, props } = usePage<SharedProps>();
  const active = activeTabFromUrl(url);
  const user = props.auth.user;
  const stravaSync = props.stravaSync ?? null;

  return (
    <header className="hidden bg-cream-deep lg:block">
      <div className="mx-auto flex w-full max-w-page items-center justify-between px-14 py-[18px] 2xl:max-w-page-2xl 2xl:px-20">
        <div className="flex items-center gap-12">
          <Link href="/" aria-label="Beranda" className="focus-ring rounded">
            <BrandMark />
          </Link>
          <nav aria-label="Primary" className="flex items-center gap-1">
            {ITEMS.map((item) => (
              <TabLink
                key={item.id}
                item={item}
                isActive={active === item.id}
              />
            ))}
          </nav>
        </div>
        <div className="flex items-center gap-3.5">
          <StravaSyncBadge sync={stravaSync} />
          {user && <UserMenu name={user.name} avatarUrl={user.avatar_url} />}
        </div>
      </div>
    </header>
  );
}

function TabLink({
  item,
  isActive,
}: Readonly<{ item: NavItem; isActive: boolean }>) {
  return (
    <Link
      href={item.href}
      aria-current={isActive ? "page" : undefined}
      className={cn(
        "focus-ring relative rounded font-mono text-sm tracking-[0.02em] transition",
        "px-[18px] py-2.5",
        isActive ? "text-ink" : "text-ink-3 hover:text-ink-2",
      )}
    >
      {item.label}
      {isActive && (
        <span
          aria-hidden
          className="absolute inset-x-[18px] -bottom-[19px] h-0.5 bg-horizon"
        />
      )}
    </Link>
  );
}
