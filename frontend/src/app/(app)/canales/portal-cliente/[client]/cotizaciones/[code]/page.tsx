import { notFound } from "next/navigation";
import Link from "@/components/Link";
import { api, ApiError, type Me } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import type { PortalQuote } from "@/lib/quotes";
import { Notice } from "@/components/crm/ui";
import { QuoteDocument } from "@/components/quotes/QuoteDocument";
import { QuoteStatus } from "@/components/quotes/ui";
import { PortalRespond } from "@/components/quotes/PortalRespond";

export default async function PortalQuotePage({ params }: { params: Promise<{ client: string; code: string }> }) {
  const { client, code } = await params;
  const [res, { data: me }] = await Promise.all([
    api<{ data: PortalQuote }>(`/portal/quotes/${code}`).catch((e) => { if (e instanceof ApiError && [403, 404].includes(e.status)) return null; throw e; }),
    api<{ data: Me }>("/me"),
  ]);
  if (!res) notFound();
  const q = res.data;
  const back = `/canales/portal-cliente/${client}/cotizaciones/${code}`;
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link href={`/canales/portal-cliente/${client}/cotizaciones`} className="text-sm text-sky-800 hover:underline">← Mis cotizaciones</Link>
        <QuoteStatus value={q.display_status} label={q.status_label} />
      </div>
      {q.notice && <Notice tone="warning">{q.notice}</Notice>}
      {q.approval && <Notice>Usted aprobó la versión {q.approval.version_no} el {dateTime(q.approval.at)}. Su asesor le contactará para confirmar la reserva.</Notice>}
      {q.rejection && <Notice tone="danger">Rechazada el {dateTime(q.rejection.at)}: {q.rejection.reason}</Notice>}
      <QuoteDocument doc={q} agency={me.tenant.name} />
      {q.can_respond && <PortalRespond code={q.code} name={me.name} back={back} />}
    </div>
  );
}
