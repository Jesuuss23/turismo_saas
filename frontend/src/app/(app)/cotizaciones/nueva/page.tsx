import Link from "@/components/Link";
import { builderData } from "@/lib/quotes-server";
import { Notice, PageHeader } from "@/components/crm/ui";
import { Builder, type Header } from "@/components/quotes/Builder";

/** PROC-021: nueva cotización. `?opp=OPP-…` precarga cliente, título, pax y moneda desde la oportunidad; `?client=CLI-…` solo el cliente. */
export default async function NewQuotePage({ searchParams }: { searchParams: Promise<{ opp?: string; client?: string }> }) {
  const sp = await searchParams;
  const { meta, products, validUntil } = await builderData();
  const back = <Link href="/cotizaciones" className="text-sm text-sky-800 hover:underline">← Cotizaciones</Link>;
  if (!meta.can.create) {
    return <><PageHeader title="Nueva cotización" actions={back} /><Notice tone="warning">Su rol no tiene el permiso quotes.create.</Notice></>;
  }

  const opp = sp.opp ? meta.opportunities.find((o) => o.code === sp.opp) : undefined;
  const client = meta.clients.find((c) => c.id === opp?.client_id || c.code === sp.client);
  const travel = opp?.travel_date && opp.travel_date >= meta.defaults.today ? opp.travel_date : "";
  const initial: Header = {
    client_id: client?.id ?? "", opportunity_id: opp?.id ?? "", title: opp?.title ?? "",
    travel_from: travel, travel_to: travel, valid_until: validUntil, currency: opp?.currency === "USD" ? "USD" : "PEN",
    pax_adt: opp?.pax ?? 2, pax_chd: 0, pax_inf: 0, discount_pct: "0", tax_pct: client ? String(Number(client.tax_pct)) : "18",
    notes: "", conditions: meta.defaults.conditions,
  };

  return (
    <>
      <PageHeader title="Nueva cotización" subtitle={opp ? `Desde la oportunidad ${opp.code}` : "Constructor de cotización"} actions={back} />
      {sp.opp && !opp && <Notice tone="warning">La oportunidad {sp.opp} no está disponible para cotizar (cerrada o fuera de su cartera, PROC-006).</Notice>}
      <Builder meta={meta} products={products} initial={initial} initialLines={[]} />
    </>
  );
}
