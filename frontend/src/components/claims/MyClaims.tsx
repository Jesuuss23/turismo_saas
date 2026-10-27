import { date } from "@/lib/crm";
import { Pill } from "@/components/admin/ui";
import { STATUS_TONE, type PortalClaim } from "@/lib/claims";

/** «Mis hojas» del portal (LR-BR-09): estado, plazo, respuesta y constancia PDF. */
export function MyClaims({ rows }: { rows: PortalClaim[] }) {
  if (!rows.length) return <p className="text-sm text-slate-500">Aún no registró hojas de reclamación.</p>;
  return (
    <ul className="space-y-3">
      {rows.map((c) => (
        <li key={c.code} className="rounded-lg border border-slate-200 bg-white p-4 text-sm" data-claim={c.book_number}>
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div>
              <p className="font-semibold text-slate-900"><span className="font-mono">{c.book_number}</span> · {c.type_label}</p>
              <p className="text-xs text-slate-500">{c.sede} · recibida el {date(c.received_at)} · responderemos a más tardar el {date(c.due_date)}</p>
            </div>
            <Pill tone={STATUS_TONE[c.book_status] ?? "slate"}>{c.status_label}</Pill>
          </div>
          <p className="mt-2 text-slate-700">{c.good}: {c.detail}</p>
          <p className="mt-1 text-xs text-slate-500">Pedido: {c.request}</p>
          {c.response && (
            <div className="mt-3 rounded-md bg-emerald-50 p-3 ring-1 ring-emerald-200">
              <p className="font-medium text-emerald-900">Respuesta del {date(c.response.sent_at)}: {c.response.decision}</p>
              <p className="mt-1 whitespace-pre-line text-emerald-900">{c.response.text}</p>
              <p className="mt-1 text-xs text-emerald-800">Acciones: {c.response.actions}</p>
            </div>
          )}
          <a href={`/bff/file?path=${encodeURIComponent(`/portal/claim-book/${c.book_number}/certificate`)}`} target="_blank" className="mt-2 inline-block text-sky-800 hover:underline">Descargar constancia (PDF)</a>
        </li>
      ))}
    </ul>
  );
}
