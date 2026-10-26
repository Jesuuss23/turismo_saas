import { date } from "@/lib/crm";
import { price } from "@/lib/catalog";
import type { PortalQuote } from "@/lib/quotes";

/** Documento de la cotización tal como lo ve el cliente (pestaña Documento, impresión, portal y enlace público). */
export function QuoteDocument({ doc, agency }: { doc: PortalQuote; agency?: string | null }) {
  const t = doc.totals;
  const pax = [doc.pax.adt && `${doc.pax.adt} adulto(s)`, doc.pax.chd && `${doc.pax.chd} niño(s)`, doc.pax.inf && `${doc.pax.inf} infante(s)`].filter(Boolean).join(", ");
  return (
    <article className="space-y-5 rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-800 print:border-0 print:p-0" data-testid="quote-document">
      <header className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
        <div>
          {agency && <p className="text-xs font-semibold uppercase tracking-wide text-sky-800">{agency}</p>}
          <h2 className="text-xl font-semibold text-slate-900">{doc.title}</h2>
          <p className="text-slate-600">Cotización {doc.code} · versión {doc.version_no}{doc.client ? ` · ${doc.client}` : ""}</p>
        </div>
        <dl className="text-right text-xs text-slate-600">
          <div>Viaje: {date(doc.travel_from)} al {date(doc.travel_to)}</div>
          <div>Pasajeros: {pax || "—"}</div>
          <div className="font-medium text-slate-800">Válida hasta {date(doc.valid_until)}</div>
        </dl>
      </header>

      <table className="w-full text-left">
        <thead className="text-xs uppercase tracking-wide text-slate-500">
          <tr><th className="py-1 pr-2">Servicio</th><th className="py-1 pr-2">Fecha</th><th className="py-1 pr-2 text-right">Cant. × pax</th><th className="py-1 pr-2 text-right">Precio unit.</th><th className="py-1 text-right">Total</th></tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {doc.items.map((i) => (
            <tr key={i.line_no} className="align-top">
              <td className="py-2 pr-2">
                <p className="font-medium">{i.product} <span className="text-xs font-normal text-slate-500">({i.segment})</span></p>
                {i.description && <p className="text-xs text-slate-600">{i.description}</p>}
                {i.inclusions.length > 0 && <p className="text-xs text-emerald-800">Incluye: {i.inclusions.join(", ")}</p>}
                {i.exclusions.length > 0 && <p className="text-xs text-slate-500">No incluye: {i.exclusions.join(", ")}</p>}
              </td>
              <td className="py-2 pr-2">{date(i.service_date)}</td>
              <td className="py-2 pr-2 text-right">{i.qty} × {i.pax}</td>
              <td className="py-2 pr-2 text-right">{price(i.unit_price, doc.currency)}</td>
              <td className="py-2 text-right font-medium">{price(i.total, doc.currency)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {t && (
        <dl className="ml-auto max-w-xs space-y-1">
          <div className="flex justify-between"><dt>Subtotal</dt><dd>{price(t.sale, doc.currency)}</dd></div>
          {Number(t.discount) > 0 && <div className="flex justify-between"><dt>Descuento ({Number(t.discount_pct)} %)</dt><dd>− {price(t.discount, doc.currency)}</dd></div>}
          <div className="flex justify-between"><dt>Valor de venta</dt><dd>{price(t.net, doc.currency)}</dd></div>
          <div className="flex justify-between"><dt>IGV ({Number(t.tax_pct)} %)</dt><dd>{price(t.tax, doc.currency)}</dd></div>
          <div className="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold"><dt>Total</dt><dd data-testid="document-total">{price(t.total, doc.currency)}</dd></div>
        </dl>
      )}

      {doc.notes && <section><h3 className="font-semibold">Notas</h3><p className="whitespace-pre-line text-slate-700">{doc.notes}</p></section>}
      {doc.conditions && <section><h3 className="font-semibold">Condiciones</h3><p className="whitespace-pre-line text-slate-700">{doc.conditions}</p></section>}
      {doc.policies.length > 0 && (
        <section>
          <h3 className="font-semibold">Políticas de cancelación y cambios</h3>
          <ul className="mt-1 space-y-1 text-xs text-slate-700">
            {doc.policies.map((p) => <li key={p.product}><strong>{p.product}:</strong> cancelación {p.cancel}; cambios {p.modify}; no show {p.noshow}; reembolso {p.refund}.</li>)}
          </ul>
        </section>
      )}
    </article>
  );
}
