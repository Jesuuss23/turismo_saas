import QRCode from "qrcode";
import { date } from "@/lib/crm";
import type { VoucherDocument } from "@/lib/ops";

/** QR en SVG generado en el servidor (sin dependencias en el navegador). */
export async function Qr({ value, size = 128 }: { value: string; size?: number }) {
  const svg = await QRCode.toString(value, { type: "svg", margin: 1, errorCorrectionLevel: "M" });
  return <div role="img" aria-label="Código QR del voucher" style={{ width: size, height: size }} dangerouslySetInnerHTML={{ __html: svg }} />;
}

/** Documento del voucher (spec 05 §2): el mismo contenido que recibe el proveedor y ve el pasajero en su app. */
export async function VoucherDoc({ code, version, status, qr, d }: { code: string; version: number; status: string; qr: string; d: VoucherDocument }) {
  const row = (k: string, v: React.ReactNode) => v ? <div><dt className="text-xs uppercase tracking-wide text-slate-500">{k}</dt><dd className="text-slate-900">{v}</dd></div> : null;
  return (
    <article className="relative rounded-lg border border-slate-300 bg-white p-5 text-sm print:border-0 print:p-0" aria-label={`Voucher ${code}`}>
      {status === "anulado" && <p className="absolute right-4 top-4 rotate-6 rounded border-2 border-rose-600 px-2 py-1 text-lg font-bold text-rose-600">ANULADO</p>}
      <header className="mb-4 flex items-start justify-between gap-4 border-b border-slate-200 pb-3">
        <div>
          <p className="text-xs uppercase tracking-wide text-slate-500">Voucher de servicio</p>
          <h2 className="text-lg font-semibold text-slate-900">{code} · v{version}</h2>
          <p className="text-slate-700">{d.service}</p>
        </div>
        <Qr value={qr} size={112} />
      </header>
      <dl className="grid gap-3 sm:grid-cols-2">
        {row("Fecha y hora", `${d.date ? date(d.date) : "—"}${d.time ? ` · ${d.time}` : ""}`)}
        {row("Proveedor", <>{d.provider}{d.provider_phone && <span className="block text-xs text-slate-500">{d.provider_phone}</span>}</>)}
        {row("Código de confirmación", d.confirmation_code ?? "Pendiente")}
        {row("Punto de encuentro", d.meeting_point)}
        {row("Reserva", `${d.reservation ?? ""}${d.client ? ` · ${d.client}` : ""}`)}
        {row("Guía", d.guide && <>{d.guide}{d.guide_phone && <span className="block text-xs text-slate-500">{d.guide_phone}</span>}</>)}
        {row("Transporte", d.transport)}
        {row("Coordinador", d.coordinator)}
      </dl>
      <section className="mt-4">
        <h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Pasajeros ({d.passengers.length})</h3>
        <ul className="grid gap-1 sm:grid-cols-2">
          {d.passengers.map((p) => <li key={p.name}>{p.name}{p.lead && " (titular)"} <span className="text-xs text-slate-500">{p.type}{p.diet && ` · ${p.diet}`}</span></li>)}
        </ul>
      </section>
      {(d.includes.length > 0 || d.excludes.length > 0) && (
        <section className="mt-4 grid gap-3 sm:grid-cols-2">
          {d.includes.length > 0 && <div><h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Incluye</h3><ul className="list-inside list-disc">{d.includes.map((x) => <li key={x}>{x}</li>)}</ul></div>}
          {d.excludes.length > 0 && <div><h3 className="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">No incluye</h3><ul className="list-inside list-disc">{d.excludes.map((x) => <li key={x}>{x}</li>)}</ul></div>}
        </section>
      )}
      {d.diets.length > 0 && <p className="mt-3 rounded bg-violet-50 px-2 py-1 text-violet-900">Dietas: {d.diets.join(" · ")}</p>}
      {d.note && <p className="mt-3 text-slate-700">Nota: {d.note}</p>}
      <footer className="mt-4 border-t border-slate-200 pt-2 text-xs text-slate-600">Contacto 24/7 de la agencia: <strong>{d.emergency}</strong></footer>
    </article>
  );
}
