import type { Metadata } from "next";
import Link from "next/link";
import { customer, prefs, siteApi } from "@/lib/site";
import type { Cart } from "@/lib/site-shared";
import { Checkout } from "@/components/site/Checkout";

export const metadata: Metadata = { title: "Checkout", robots: { index: false } };

export default async function CheckoutPage() {
  const { locale } = await prefs();
  const [cart, me] = await Promise.all([siteApi<{ data: Cart }>("carts/current", { cart: true }).then((r) => r.data).catch(() => null), customer()]);
  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <h1 className="mb-4 text-2xl font-semibold">{locale === "en" ? "Checkout" : "Finalizar compra"}</h1>
      {!cart || cart.lines.length === 0 ? (
        <p className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">{locale === "en" ? "Your cart is empty." : "Su carrito está vacío."} <Link href="/sitio/tours" className="text-sky-700 underline">{locale === "en" ? "Explore tours" : "Ver tours"}</Link></p>
      ) : <Checkout cart={cart} me={me} locale={locale} />}
    </div>
  );
}
