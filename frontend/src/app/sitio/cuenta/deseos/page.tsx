import type { Metadata } from "next";
import Link from "next/link";
import { AccountShell } from "@/components/site/AccountShell";
import { WishButton } from "@/components/site/WishButton";
import { siteApi } from "@/lib/site";

export const metadata: Metadata = { title: "Lista de deseos", robots: { index: false } };

export default function WishlistPage() {
  return (
    <AccountShell active="/sitio/cuenta/deseos">
      {async (_me, en) => {
        const rows = (await siteApi<{ data: { id: string; slug: string; name: string }[] }>("me/wishlist")).data;
        return (
          <section>
            <h2 className="text-lg font-semibold">{en ? "Wishlist" : "Lista de deseos"}</h2>
            {rows.length === 0 ? <p className="mt-2 text-sm text-slate-600">{en ? "Save tours with ♡ to find them here." : "Guarde tours con ♡ para encontrarlos aquí."}</p> : (
              <ul className="mt-3 space-y-2" data-testid="wishlist">
                {rows.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white p-3 text-sm">
                    <Link href={`/sitio/tours/${p.slug}`} className="font-medium hover:underline">{p.name}</Link>
                    <WishButton slug={p.slug} logged locale={en ? "en" : "es"} initial />
                  </li>
                ))}
              </ul>
            )}
          </section>
        );
      }}
    </AccountShell>
  );
}
