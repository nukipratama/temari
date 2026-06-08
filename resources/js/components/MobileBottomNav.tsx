import { Link, usePage } from "@inertiajs/react";
import { Icon } from "@iconify/react";
import { cn } from "@/lib/cn";
import { activeTabFromUrl } from "./TopNav";
import type { SharedProps } from "@/types/inertia";

interface NavItem {
  id: "hari-ini" | "koleksi" | "riwayat" | "aku";
  label: string;
  icon: string;
  href: string;
}

const ITEMS: ReadonlyArray<NavItem> = [
  {
    id: "hari-ini",
    label: "Hari Ini",
    icon: "mdi:weather-sunset-up",
    href: "/",
  },
  {
    id: "koleksi",
    label: "Koleksi",
    icon: "mdi:cards-outline",
    href: "/kartu",
  },
  { id: "riwayat", label: "Riwayat", icon: "mdi:history", href: "/aktivitas" },
  { id: "aku", label: "Aku", icon: "mdi:account-outline", href: "/profil" },
];

export default function MobileBottomNav() {
  const { url } = usePage<SharedProps>();
  const active = activeTabFromUrl(url);

  return (
    <nav
      aria-label="Primary"
      className="fixed inset-x-0 bottom-0 z-30 flex justify-around rounded-t-[18px] bg-sky px-2 pb-[max(1.75rem,env(safe-area-inset-bottom))] pt-2.5 lg:hidden"
    >
      {ITEMS.map((item) => {
        const isActive = active === item.id;
        return (
          <Link
            key={item.id}
            href={item.href}
            className={cn(
              "focus-ring-on-sky flex flex-col items-center gap-1 rounded-lg px-4 py-2 transition",
              isActive ? "text-horizon" : "text-cream/[0.55]",
            )}
            aria-current={isActive ? "page" : undefined}
          >
            <Icon icon={item.icon} width={20} height={20} aria-hidden />
            <span
              className={cn(
                "text-[11px]",
                isActive ? "font-semibold" : "font-normal",
              )}
            >
              {item.label}
            </span>
          </Link>
        );
      })}
    </nav>
  );
}
