import Link from "@/components/Link";
import { api } from "@/lib/api";
import { fetchScoped } from "@/lib/crm-server";
import { date, dateTime } from "@/lib/crm";
import { price } from "@/lib/catalog";
import { OutOfScope } from "@/components/crm/ui";
import { PrintButton } from "@/components/quotes/PrintButton";

type Confirmation = {
  code: string; title: string; status: string; status_label: string; client: { name: string; code: string; email: string | null }; owner: { name: string } | null;
  channel: string; travel_from: string; travel_to: string; currency: string; total: string;
  passengers: { name: string; type: string; doc: string; placeholder: boolean }[];
  services: { name: string; date: string; time: string | null; pax: number | null; status: string; confirmation_code: string | null }[];
  payment_plan: { concept: string; amount: string; due_date: string; status: string }[];
  policies: { product: string; cancel: string | null; modify: string | null }[]; issued_at: string;
};

/** Documento de confirmación de reserva (vista de impresión; reemplaza al PDF del job `documents`). Sin costos ni datos internos. */
export default async function ConfirmationPage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const res = await fetchScoped<{ data: Confirmation }>(`/reservations/${code}/documents/confirmation`);
  if (!res) return <OutOfScope back="/reservas" />;
  const d = res.data;
  const { data: me } = await api<{ data: { tenant: { name: string } } }>("/me");
  return (
    <div className="mx-auto max-w-3xl">
      <div className="mb-4 flex items-center justify-between print:hidden">
        <Link href={`/reservas/${d.code}`} className="text-sm text-sky-800 hover:underline">← Volver</Link>
        <PrintButton />
      </div>
      <article className="space-y-5 rounded-lg border border-slate-200 bg-white p-8 text-sm print:border-0 print:p-0">
        <header className="flex items-start justify-between border-b border-slate-200 pb-4">
          <div><p className="text-lg font-semibold text-slate-900">{me.tenant.name}</p><p className="text-slate-500">Confirmación de reserva</p></div>
          <div className="text-right"><p className="text-lg font-semibold">{d.code}</p><p className="text-slate-500">{d.status_label}</p></div>
        </header>
        <section className="grid grid-cols-2 gap-2">
          <p><span className="text-slate-500">Cliente:</span> {d.client.name} ({d.client.code})</p>
          <p><span className="text-slate-500">Asesor:</span> {d.owner?.name}</p>
          <p><span className="text-slate-500">Viaje:</span> {date(d.travel_from)}{d.travel_to !== d.travel_from && <> al {date(d.travel_to)}</>}</p>
          <p><span className="text-slate-500">Total:</span> <strong>{price(d.total, d.currency)}</strong></p>
          <p className="col-span-2 font-medium">{d.title}</p>
        </section>
        <section><h2 className="mb-1 font-semibold">Pasajeros</h2>
          <ul>{d.passengers.map((p, i) => <li key={i}>{p.name} · {p.type}{p.doc ? ` · ${p.doc}` : ""}</li>)}</ul></section>
        <section><h2 className="mb-1 font-semibold">Servicios</h2>
          <table className="w-full"><tbody>{d.services.map((s, i) => (
            <tr key={i} className="border-t border-slate-100"><td className="py-1">{date(s.date)}{s.time ? ` ${s.time}` : ""}</td><td>{s.name}</td><td>{s.pax} pax</td><td>{s.status}{s.confirmation_code ? ` · ${s.confirmation_code}` : ""}</td></tr>
          ))}</tbody></table></section>
        {d.payment_plan.length > 0 && <section><h2 className="mb-1 font-semibold">Plan de pagos</h2>
          <ul>{d.payment_plan.map((p, i) => <li key={i}>{p.concept}: {price(p.amount, d.currency)} · vence {date(p.due_date)} · {p.status}</li>)}</ul></section>}
        <section><h2 className="mb-1 font-semibold">Políticas</h2>
          {d.policies.map((p) => <p key={p.product}><strong>{p.product}:</strong> {p.cancel}{p.modify ? ` Cambios: ${p.modify}` : ""}</p>)}</section>
        <footer className="border-t border-slate-200 pt-3 text-xs text-slate-500">Emitido el {dateTime(d.issued_at)}</footer>
      </article>
    </div>
  );
}
