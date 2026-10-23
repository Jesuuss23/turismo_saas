import { api, type Me } from "@/lib/api";
import { SalesPanel } from "./SalesPanel";

export default async function DashboardPage({ searchParams }: { searchParams: Promise<{ done?: string }> }) {
  const { data: me } = await api<{ data: Me }>("/me");
  if (me.role?.name === "sales") return <SalesPanel me={me} done={(await searchParams).done} />;

  return (
    <section className="space-y-2">
      <h1 className="text-2xl font-semibold text-slate-900">Hola, {me.name}</h1>
      <p className="text-slate-600">{me.role?.display_name} · {me.scope_label} · Sede {me.sede?.name}</p>
      <p className="text-sm text-slate-500">Los indicadores del dashboard llegan con los módulos 02–09.</p>
    </section>
  );
}
