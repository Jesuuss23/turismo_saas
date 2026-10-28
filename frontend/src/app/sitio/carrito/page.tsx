import type { Metadata } from "next";
import { prefs, siteApi } from "@/lib/site";
import type { Cart } from "@/lib/site-shared";
import { CartView } from "@/components/site/CartView";
import { TrackOnView } from "@/components/site/TrackOnView";

export const metadata: Metadata = { title: "Carrito", robots: { index: false } };

export default async function CartPage() {
  const { locale } = await prefs();
  const cart = await siteApi<{ data: Cart }>("carts/current", { cart: true }).then((r) => r.data).catch(() => null);
  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="mb-4 text-2xl font-semibold">{locale === "en" ? "Your cart" : "Su carrito"}</h1>
      {cart && <TrackOnView event="view_cart" params={{ currency: cart.currency, value: cart.total }} />}
      <CartView initial={cart} locale={locale} />
    </div>
  );
}
