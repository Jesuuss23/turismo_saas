import { api } from "@/lib/api";
import { amount, type SupplierDashboard } from "@/lib/supplier";
import { supplierMe } from "@/lib/supplier-server";
import { Kpis } from "@/components/crm/ui";

/** Marco común de la extranet (spec 06 §2): proveedor de la sesión y los 4 KPIs. */
export default async function SupplierLayout({ children }: { children: React.ReactNode }) {
  const { me, blocked } = await supplierMe();
  if (!me) {
    return (
      <section className="mx-auto max-w-lg rounded-lg border border-amber-200 bg-amber-50 p-6 text-center" role="alert">
        <h1 className="text-lg font-semibold text-amber-900">Extranet de proveedores</h1>
        <p className="mt-2 text-sm text-amber-900">{blocked}</p>
        <p className="mt-2 text-xs text-amber-800">El personal interno gestiona proveedores desde Catálogo › Proveedores.</p>
      </section>
    );
  }
  const { data: d } = await api<{ data: SupplierDashboard }>("/supplier/dashboard");
  const balances = Object.entries(d.balance);

  return (
    <>
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm text-slate-600"><span className="font-medium text-slate-900">{me.provider.name}</span> · {me.provider.ref} · {me.user.name} ({me.user.provider_role_label})</p>
        <p className="text-xs text-slate-500">Cliente: {me.agency}</p>
      </div>
      <Kpis items={[
        { label: "Solicitudes por responder", value: d.pending_requests,
          hint: <>{d.alternatives_in_review > 0 && <span className="block">{d.alternatives_in_review} con alternativa en revisión</span>}Responder en máx. {d.response_target_hours} h</> },
        { label: "Llegadas próximos 7 días", value: d.arrivals_7d.services, hint: `${d.arrivals_7d.pax} pax` },
        { label: "Saldo por cobrar a la agencia", value: balances.length ? <span className="block text-lg leading-tight">{balances.map(([c, v]) => <span key={c} className="block">{amount(v, c)}</span>)}</span> : "—",
          hint: "Por moneda, sin conversión" },
        { label: "Incidencias abiertas", value: d.open_incidents },
      ]} />
      {children}
    </>
  );
}
