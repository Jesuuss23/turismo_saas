import Link from "next/link";
import { logout } from "@/app/actions";
import type { AccessInfo, Me } from "@/lib/api";

/** Shell de experiencias externas (portales, extranet, app operador): sin menú lateral ni búsqueda (§7.7). */
export function ExternalShell({ me, access, children }: { me: Me; access: AccessInfo; children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <header className="bg-sky-800 text-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <p className="font-semibold">{me.tenant.name} · {access.label}</p>
          <nav className="flex flex-wrap gap-4 text-sm">
            {access.nav.map((i) => <Link key={i.route} href={`/${i.route}`} className="hover:underline">{i.label}</Link>)}
          </nav>
          <form action={logout}><button className="text-sm text-sky-100 hover:text-white">Salir</button></form>
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 p-6">{children}</main>
      <footer className="py-4 text-center text-xs text-slate-500">Solo se muestran datos vinculados a su cuenta</footer>
    </div>
  );
}
