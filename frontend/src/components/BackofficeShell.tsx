import Link from "next/link";
import { logout } from "@/app/actions";
import type { AccessInfo, Me } from "@/lib/api";

/** Shell del back-office: menú lateral filtrado por pantalla (misma regla que la URL) y barra superior. */
export function BackofficeShell({ me, access, unread, children }: { me: Me; access: AccessInfo; unread: number; children: React.ReactNode }) {
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
        <header className="flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3">
          <span className="rounded-full bg-sky-50 px-3 py-1 text-xs font-medium text-sky-800 ring-1 ring-sky-200">
            Tenant: {me.tenant.slug} · {me.scope_label}
          </span>
          <div className="flex items-center gap-4 text-sm">
            <span className="text-slate-600" title="Notificaciones">🔔 {unread}</span>
            <Link href="/perfil" className="text-slate-700 hover:underline">{me.name} · {me.role?.display_name}</Link>
            <form action={logout}><button className="text-slate-500 hover:text-slate-900">Salir</button></form>
          </div>
        </header>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </div>
  );
}
