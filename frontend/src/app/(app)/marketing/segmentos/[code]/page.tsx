import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { btnCls, btnGhostCls, Card, Notice, PageHeader, Timeline } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { BackLink } from "@/components/marketing/ui";
import { SegmentBuilder } from "@/components/marketing/SegmentBuilder";
import type { Options, Segment } from "@/lib/marketing";

/** Constructor de segmentos (`/marketing/segmentos/nuevo` o `/marketing/segmentos/SEG-…`). */
export default async function SegmentPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<{ saved?: string }> }) {
  const { code } = await params;
  const { saved } = await searchParams;
  const isNew = code === "nuevo";
  let segment: Segment | null = null;
  if (!isNew) {
    try {
      segment = (await api<{ data: Segment }>(`/marketing/segments/${encodeURIComponent(code)}`)).data;
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) notFound();
      throw e;
    }
  }
  const options = (await api<{ data: Options }>("/marketing/options")).data;

  return (
    <>
      <BackLink href="/marketing/campanas?tab=segmentos">Segmentos</BackLink>
      <PageHeader title={segment ? `${segment.code} · ${segment.name}` : "Nuevo segmento"} subtitle="Dinámico: las reglas se evalúan al enviar cada campaña."
        actions={segment && <>
          <Link href={`/marketing/campanas/nueva?segment=${segment.code}`} className={btnCls}>Crear campaña</Link>
          <ActionForm path={`/marketing/segments/${segment.code}/duplicate`} inline submitLabel="Duplicar" submitClass={btnGhostCls} redirectTo="/marketing/segmentos/{data.code}" />
          {segment.campaigns.length === 0 && (
            <ActionForm path={`/marketing/segments/${segment.code}`} method="DELETE" ifMatch={segment.lock_version} inline submitLabel="Eliminar" confirm={`¿Eliminar ${segment.code}?`}
              redirectTo="/marketing/campanas?tab=segmentos" submitClass="rounded-md border border-rose-300 bg-white px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-50" />
          )}
        </>} />
      {saved && <Notice>Segmento {segment?.code} guardado.</Notice>}
      {segment && segment.campaigns.length > 0 && <Notice tone="warning">Lo usan {segment.campaigns.join(", ")}: no se puede eliminar.</Notice>}
      <SegmentBuilder segment={segment} fields={options.fields} />
      {segment?.history && <Card title="Historial" className="mt-4"><Timeline rows={segment.history} /></Card>}
    </>
  );
}
