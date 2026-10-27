import { redirect } from "next/navigation";
import { api, type Me } from "@/lib/api";
import { isPlatformSession } from "@/lib/session";
import { SalesPanel } from "./SalesPanel";
import { AccountingPanel, FinancePanel } from "./FinancePanels";
import { ExecutivePanel } from "./ExecutivePanel";

export default async function DashboardPage({ searchParams }: { searchParams: Promise<{ done?: string }> }) {
  if (await isPlatformSession()) redirect("/admin/tenants");
  const { data: me } = await api<{ data: Me }>("/me");
  if (me.role?.name === "sales") return <SalesPanel me={me} done={(await searchParams).done} />;
  if (me.role?.name === "finance") return <FinancePanel me={me} />;
  if (me.role?.name === "accounting") return <AccountingPanel me={me} />;
  if (me.role?.name === "tenant_admin" || me.role?.name === "manager") return <ExecutivePanel me={me} />;

  return (
    <section className="space-y-2">
      <h1 className="text-2xl font-semibold text-slate-900">Hola, {me.name}</h1>
      <p className="text-slate-600">{me.role?.display_name} · {me.scope_label} · Sede {me.sede?.name}</p>
    </section>
  );
}
