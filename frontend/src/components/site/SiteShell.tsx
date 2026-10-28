import Link from "next/link";
import { cartCount, customer, prefs, siteInfo } from "@/lib/site";
import { t } from "@/lib/site-shared";
import { PrefsSwitch } from "./PrefsSwitch";
import { ChatWidget } from "./ChatWidget";
import { NewsletterForm } from "./NewsletterForm";
import { Analytics } from "./Analytics";

/** Cabecera, pie y chat del sitio público (spec 15 §2 «Pie de página», chat flotante). */
export async function SiteShell({ children }: { children: React.ReactNode }) {
  const [{ locale, currency }, site, me, count] = await Promise.all([prefs(), siteInfo(), customer(), cartCount()]);
  const d = t(locale);
  const nav = [
    ["/sitio/destinos", d.destinations], ["/sitio/tours", d.tours], ["/sitio/paquetes", d.packages], ["/sitio/experiencias", d.experiences],
    ["/sitio/ofertas", d.offers], ["/sitio/viajes-a-medida", d.tailor], ["/sitio/blog", d.blog], ["/sitio/contacto", d.contact],
  ] as const;
  return (
    <div className="flex min-h-screen flex-col bg-slate-50 text-slate-900" lang={locale}>
      {site.banner && (
        <div className="bg-amber-400 px-4 py-1.5 text-center text-sm font-medium text-slate-900">
          {site.banner.title} · <span className="font-normal">{site.banner.body}</span>
        </div>
      )}
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
          <Link href="/sitio" className="flex min-w-0 items-center gap-2 font-semibold text-[#0b1f3a]">
            <span aria-hidden className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-[#0b1f3a] text-sm text-amber-300">▲</span>
            <span className="truncate">{site.name}</span>
          </Link>
          <nav aria-label="Principal" className="ml-4 hidden flex-1 items-center gap-4 text-sm lg:flex">
            {nav.map(([href, label]) => <Link key={href} href={href} className="text-slate-700 hover:text-[#0b1f3a]">{label}</Link>)}
          </nav>
          <div className="ml-auto flex items-center gap-2">
            <PrefsSwitch locale={locale} currency={currency} />
            <Link href="/sitio/carrito" className="relative rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm" aria-label={`${d.cart} (${count})`}>
              🛒<span data-testid="cart-count" className="ml-1 font-semibold">{count}</span>
            </Link>
            <Link href={me ? "/sitio/cuenta" : "/sitio/cuenta/ingresar"} className="hidden rounded-lg bg-[#0b1f3a] px-3 py-1.5 text-sm text-white sm:inline-block">
              {me ? me.name.split(" ")[0] : d.signin}
            </Link>
          </div>
        </div>
        <details className="border-t border-slate-100 lg:hidden">
          <summary className="cursor-pointer px-4 py-2 text-sm text-slate-700">Menú</summary>
          <nav aria-label="Móvil" className="grid grid-cols-2 gap-1 px-4 pb-3 text-sm">
            {nav.map(([href, label]) => <Link key={href} href={href} className="rounded px-2 py-1.5 hover:bg-slate-100">{label}</Link>)}
            <Link href={me ? "/sitio/cuenta" : "/sitio/cuenta/ingresar"} className="rounded px-2 py-1.5 hover:bg-slate-100">{me ? d.account : d.signin}</Link>
          </nav>
        </details>
      </header>
      <main className="flex-1">{children}</main>
      <footer className="mt-12 bg-[#0b1f3a] text-slate-200">
        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-4">
          <div className="min-w-0">
            <p className="font-semibold text-white">{site.name}</p>
            <p className="mt-1 text-sm">{site.legal_name} · RUC {site.ruc}</p>
            <p className="mt-1 text-sm">{site.fiscal_address}</p>
            <ul className="mt-3 space-y-1 text-sm">
              {site.sedes.map((s) => <li key={s.code}>{s.name}{s.address ? ` — ${s.address}` : ""}</li>)}
            </ul>
          </div>
          <div className="text-sm">
            <p className="font-semibold text-white">{d.contact}</p>
            <p className="mt-2 select-all">WhatsApp {site.whatsapp}</p>
            <p className="select-all">{locale === "en" ? "Phone" : "Teléfono"} {site.phone}</p>
            <p className="select-all">{locale === "en" ? "Emergencies 24/7" : "Emergencias 24/7"} {site.emergency}</p>
            <p className="mt-2">{site.hours}</p>
            <p className="mt-2">{site.socials.join(" · ")}</p>
          </div>
          <div className="text-sm">
            <p className="font-semibold text-white">{d.legal}</p>
            <ul className="mt-2 space-y-1">
              <li><Link href="/sitio/legal/terminos-y-condiciones" className="hover:underline">{locale === "en" ? "Terms & conditions" : "Términos y condiciones"}</Link></li>
              <li><Link href="/sitio/legal/politica-de-privacidad" className="hover:underline">{locale === "en" ? "Privacy policy" : "Política de privacidad"}</Link></li>
              <li><Link href="/sitio/legal/politica-de-cancelacion" className="hover:underline">{locale === "en" ? "Cancellation policy" : "Política de cancelación"}</Link></li>
              <li><Link href="/sitio/legal/politica-de-cookies" className="hover:underline">Cookies</Link></li>
              <li><Link href="/sitio/nosotros" className="hover:underline">{d.about}</Link></li>
              <li><Link href="/sitio/cuenta/mi-reserva" className="hover:underline">{locale === "en" ? "Manage my booking" : "Gestionar mi reserva"}</Link></li>
              <li><Link href="/sitio/libro-reclamaciones" className="font-medium text-amber-300 hover:underline">📕 {d.claimBook}</Link></li>
            </ul>
          </div>
          <div className="min-w-0 text-sm">
            <p className="font-semibold text-white">{d.newsletter}</p>
            <NewsletterForm locale={locale} dark />
            <p className="mt-4">{site.payment_methods.join(" · ")}</p>
            <p className="mt-2 text-xs text-slate-300">{d.fxToday}: US$ 1 = S/ {Number(site.fx.usd_pen).toFixed(3)} ({site.fx.date}) · {site.fx.source}</p>
          </div>
        </div>
      </footer>
      <ChatWidget locale={locale} />
      <Analytics currency={currency} locale={locale} />
    </div>
  );
}
