import { api, type Me } from "@/lib/api";

export default async function DashboardPage() {
  const { data: me } = await api<{ data: Me }>("/me");

  return (
    <section className="space-y-2">
      <h1 className="text-2xl font-semibold text-slate-900">Hola, {me.name}</h1>
      <p className="text-slate-600">{me.role?.display_name} · {me.scope_label} · Sede {me.sede?.name}</p>
      <p className="text-sm text-slate-500">Los indicadores del dashboard llegan con los módulos 01–09.</p>
    </section>
  );
}
