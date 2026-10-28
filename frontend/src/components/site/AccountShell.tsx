import Link from "next/link";
import { redirect } from "next/navigation";
import { customer, prefs } from "@/lib/site";
import type { Customer } from "@/lib/site-shared";
import { LogoutButton } from "./AccountForms";

/** Marco de «Mi cuenta»: exige sesión de cliente (SIT-BR-20) y muestra el menú de secciones. */
export async function AccountShell({ active, children }: { active: string; children: (me: Customer, en: boolean) => React.ReactNode }) {
  const me = await customer();
  if (!me) redirect("/sitio/cuenta/ingresar");
  const { locale } = await prefs();
  const en = locale === "en";
  const items = [
    ["/sitio/cuenta", en ? "My bookings" : "Mis reservas"], ["/sitio/cuenta/vouchers", "Vouchers"], ["/sitio/cuenta/pagos", en ? "Pending payments" : "Pagos pendientes"],
    ["/sitio/cuenta/deseos", en ? "Wishlist" : "Lista de deseos"], ["/sitio/cuenta/datos", en ? "Personal data" : "Datos personales"],
  ];
  return (
    <div className="mx-auto max-w-6xl px-4 py-8">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-2xl font-semibold">{en ? "Hello" : "Hola"}, {me.name.split(" ")[0]}</h1>
        <LogoutButton en={en} />
      </div>
      <p className="text-sm text-slate-500">{me.client.code} · {me.email}</p>
      <div className="mt-4 grid gap-6 md:grid-cols-[220px_1fr]">
        <nav aria-label={en ? "Account" : "Cuenta"} className="flex flex-wrap gap-2 md:flex-col">
          {items.map(([href, label]) => <Link key={href} href={href} aria-current={href === active ? "page" : undefined} className={`rounded-lg px-3 py-2 text-sm ${href === active ? "bg-[#0b1f3a] text-white" : "bg-white hover:bg-slate-100"}`}>{label}</Link>)}
          <a href={me.portal_url} className="rounded-lg px-3 py-2 text-sm text-sky-700 hover:underline">{en ? "Full client portal →" : "Portal del cliente completo →"}</a>
        </nav>
        <div className="min-w-0">{children(me, en)}</div>
      </div>
    </div>
  );
}
