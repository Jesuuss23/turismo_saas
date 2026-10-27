import Link from "@/components/Link";
import { logout } from "@/app/actions";
import type { AccessInfo, Me } from "@/lib/api";

/** Shell del back-office: menú lateral filtrado por pantalla (misma regla que la URL) y barra superior. */
export function BackofficeShell({ me, access, unread, platform = false, children }: { me: Me; access: AccessInfo; unread: number; platform?: boolean; children: React.ReactNode }) {
  const groups = access.nav.reduce<Record<string, AccessInfo["nav"]>>((acc, item) => {
    (acc[item.group ?? "General"] ??= []).push(item);
    return acc;
  }, {});

  return (
    <div className="flex min-h-screen bg-slate-50">
      <aside className="hidden w-60 shrink-0 border-r border-slate-200 bg-white md:block">
        <div className="border-b border-slate-200 px-4 py-4">
          <p className="text-sm font-semibold text-slate-900">{me.tenant.name}</p>
          <p className="text-xs text-slate-500">{access.label}</p>
        </div>
        <nav className="space-y-4 p-3 text-sm">
          {Object.entries(groups).map(([group, items]) => (
            <div key={group}>
              <p className="px-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{group}</p>
              <ul className="mt-1">
                {items.map((i) => (
                  <li key={i.route}>
                    <Link href={`/${i.route}`} className="block rounded px-2 py-1.5 text-slate-700 hover:bg-slate-100">{i.label}</Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3">
          {platform ? (
            <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-medium text-violet-800 ring-1 ring-violet-200">
              Plataforma · {me.scope_label}
            </span>
          ) : (
            <span className="rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-800 ring-1 ring-sky-200">
              Tenant: {me.tenant.slug} · {me.scope_label}
            </span>
          )}
          <nav className="flex gap-2 overflow-x-auto text-xs md:hidden" aria-label="Menú">
            {access.nav.slice(0, 6).map((i) => <Link key={i.route} href={`/${i.route}`} className="whitespace-nowrap text-slate-600">{i.label}</Link>)}
          </nav>
          <div className="flex items-center gap-4 text-sm">
            {!platform && <span className="text-slate-600" title="Notificaciones">🔔 {unread}</span>}
            {platform ? <span className="text-slate-700">{me.name} · {me.role?.display_name}</span>
              : <Link href="/perfil" className="text-slate-700 hover:underline">{me.name} · {me.role?.display_name}</Link>}
            <form action={logout}><button className="text-slate-500 hover:text-slate-900">Salir</button></form>
          </div>
        </header>
        <main className="min-w-0 flex-1 p-4 md:p-6">{children}</main>
      </div>
    </div>
  );
}
