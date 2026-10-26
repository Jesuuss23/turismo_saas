import { api, ApiError } from "@/lib/api";
import { dateTime } from "@/lib/crm";
import type { PortalQuote } from "@/lib/quotes";
import { Notice } from "@/components/crm/ui";
import { QuoteDocument } from "@/components/quotes/QuoteDocument";
import { QuoteStatus } from "@/components/quotes/ui";
import { PublicRespond } from "@/components/quotes/PortalRespond";
import { respondPublic } from "./actions";

export const metadata = { title: "Su cotización", robots: { index: false } };

/** Enlace público de la cotización (spec 03 §6): sin sesión, abrirlo registra la vista (QTE-BR-22). */
export default async function PublicQuotePage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const res = /^[\w-]{20,128}$/.test(token)
    ? await api<{ data: PortalQuote }>(`/public/quotes/${token}`, { token: "" }).catch((e) => { if (e instanceof ApiError && e.status < 500) return null; throw e; })
    : null;

  return (
    <main className="min-h-screen bg-slate-50 px-4 py-8">
      <div className="mx-auto max-w-3xl space-y-4">
        {!res ? (
          <Notice tone="warning">El enlace no es válido o fue reemplazado por uno nuevo. Pida a su asesor que se lo vuelva a enviar.</Notice>
        ) : (
          <>
            <div className="flex justify-end"><QuoteStatus value={res.data.display_status} label={res.data.status_label} /></div>
            {res.data.notice && <Notice tone="warning">{res.data.notice}</Notice>}
            {res.data.approval && <Notice>Cotización aprobada por {res.data.approval.by_name} el {dateTime(res.data.approval.at)}.</Notice>}
            {res.data.rejection && <Notice tone="danger">Cotización rechazada el {dateTime(res.data.rejection.at)}.</Notice>}
            <QuoteDocument doc={res.data} agency={res.data.agency} />
            {res.data.can_respond && <PublicRespond action={respondPublic.bind(null, token)} />}
          </>
        )}
      </div>
    </main>
  );
}
