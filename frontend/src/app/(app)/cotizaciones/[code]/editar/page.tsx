import { redirect } from "next/navigation";
import Link from "@/components/Link";
import { fetchScoped } from "@/lib/crm-server";
import { builderData, linesFromItems } from "@/lib/quotes-server";
import type { Quote } from "@/lib/quotes";
import { Notice, OutOfScope, PageHeader } from "@/components/crm/ui";
import { Builder, type Header } from "@/components/quotes/Builder";

/** Editar la versión vigente: solo en borrador (QTE-BR-19, versiones enviadas congeladas). */
export default async function EditQuotePage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const res = await fetchScoped<{ data: Quote }>(`/quotes/${code}`);
  if (!res) return <OutOfScope back="/cotizaciones" />;
  const q = res.data;
  const v = q.version;
  if (!v || q.status !== "borrador" || v.frozen_at) redirect(`/cotizaciones/${code}`);
  const { meta, products } = await builderData();
  if (!meta.can.create) {
    return <><PageHeader title={`Editar ${q.code}`} /><Notice tone="warning">Su rol no tiene el permiso quotes.create.</Notice></>;
  }

  const initial: Header = {
    client_id: q.client?.id ?? "", opportunity_id: q.opportunity?.id ?? "", title: q.title, travel_from: q.travel_from, travel_to: q.travel_to,
    valid_until: q.valid_until, currency: q.currency === "USD" ? "USD" : "PEN", pax_adt: q.pax.adt, pax_chd: q.pax.chd, pax_inf: q.pax.inf,
    discount_pct: String(Number(v.discount_pct)), tax_pct: String(Number(v.tax_pct)), notes: v.notes ?? "", conditions: v.conditions ?? "",
  };
  const lines = await linesFromItems(v.items, { currency: q.currency, clientId: q.client?.id ?? null, fx: v.fx_rate });

  return (
    <>
      <PageHeader title={`Editar ${q.code} · versión ${v.version_no}`} subtitle={q.title}
        actions={<Link href={`/cotizaciones/${q.code}`} className="text-sm text-sky-800 hover:underline">← Volver a la cotización</Link>} />
      {q.internal_approval && ["pendiente", "aprobada"].includes(q.internal_approval.state) && (
        <Notice tone="warning">Al guardar cambios, la aprobación interna {q.internal_approval.state === "pendiente" ? "pendiente" : "otorgada"} queda anulada y deberá solicitarse de nuevo si sigue siendo necesaria (QTE-BR-10).</Notice>
      )}
      <Builder meta={meta} products={products} initial={initial} initialLines={lines} edit={{ code: q.code, version: v.version_no, lock: v.lock_version }} />
    </>
  );
}
