import { Link } from "@inertiajs/react";
import { type MouseEventHandler, type ReactNode } from "react";
import { cn } from "@/lib/cn";
import { cardVariants } from "@/lib/variants";
import { type CardPadding, type CardTone } from "./Card";

interface LinkCardProps {
  href: string;
  /** Default 'cream'. */
  tone?: CardTone;
  /** Default 'md' — px-5 py-5. */
  padding?: CardPadding;
  onClick?: MouseEventHandler<Element>;
  className?: string;
  children: ReactNode;
}

export default function LinkCard({
  href,
  tone = "cream",
  padding = "md",
  onClick,
  className,
  children,
}: Readonly<LinkCardProps>) {
  return (
    <Link
      href={href}
      onClick={onClick}
      className={cn(cardVariants({ tone, padding }), "block focus-ring", className)}
    >
      {children}
    </Link>
  );
}
