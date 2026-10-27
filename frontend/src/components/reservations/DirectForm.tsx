"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import type { ResMeta } from "@/lib/reservations";
import { btnCls, btnGhostCls, Card, inputCls, Notice } from "@/components/crm/ui";

export type DirectProduct = {
  id: string; ref: string; name: string; type: string | null; availability_model: string; by_departure: boolean; advance_min_days: number; max_pax: number;
  duration: string; blocked_reason: string | null;
  rates: { id: string; ref: string; segment: string; channel: string; currency: string; price: string; valid_from: string; valid_to: string }[];
  departures: { id: string; ref: string; date: string; time: string; capacity: number; available: number; status: string }[];
};

type Preview = {
  currency: string; adult_price: string; child_price: string; child_rate: string | null; total: string; cost: string; season: { name: string; factor: string } | null;
  availability: { ok: boolean; message: string; provider_hold: boolean; alternatives: { ref: string; date: string; available: number }[] }; travel_to: string; date: string;
};

const label = "mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500";

/** Formulario de reserva directa con panel de disponibilidad y precio calculado por el backend (RES-BR-04/05/06). */
export function DirectForm({ meta, products }: { meta: ResMeta; products: DirectProduct[] }) {
  const router = useRouter();
  const [f, setF] = useState({ client_id: "", channel: "", owner_id: meta.own_only ? meta.owners[0]?.id ?? "" : "", product_id: "", rate_id: "", departure_id: "", date: "", adt: 2, chd: 0, inf: 0, notes: "" });
  const [preview, setPreview] = useState<Preview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [idem] = useState(() => crypto.randomUUID());
  const product = products.find((p) => p.id === f.product_id);
  const rates = useMemo(() => (product?.rates ?? []).filter((r) => r.segment !== "Niño"), [product]);
  const set = (patch: Partial<typeof f>) => setF((prev) => ({ ...prev, ...patch }));
  const ready = f.client_id && f.product_id && f.rate_id && (product?.by_departure ? f.departure_id : f.date) && f.adt + f.chd > 0;

  useEffect(() => {
    if (!ready) { setPreview(null); return; }
    const t = setTimeout(async () => {
      const res = await callApi("/reservations/availability-check", "POST", body());
      if (res.error) { setError(res.error + (res.fieldErrors ? ` · ${Object.values(res.fieldErrors).flat().join(" · ")}` : "")); setPreview(null); }
      else { setError(null); setPreview(res.data as Preview); }
    }, 250);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [f.client_id, f.product_id, f.rate_id, f.departure_id, f.date, f.adt, f.chd, f.inf]);

  function body() {
    return {
      client_id: f.client_id, product_id: f.product_id, rate_id: f.rate_id, adt: f.adt, chd: f.chd, inf: f.inf,
      ...(product?.by_departure ? { departure_id: f.departure_id } : { date: f.date }),
      channel: f.channel || null, owner_id: f.owner_id || null, notes: f.notes || null,
    };
  }

  async function submit(confirm: boolean) {
    setBusy(true);
    const res = await callApi("/reservations", "POST", { ...body(), confirm }, { "Idempotency-Key": `${idem}-${confirm ? "c" : "d"}` });
    setBusy(false);
    if (res.error) { setError(res.error + (res.fieldErrors ? ` · ${Object.values(res.fieldErrors).flat().join(" · ")}` : "")); return; }
    router.push(`/reservas/${(res.data as { code: string }).code}`);
  }

  const pax = f.adt + f.chd;
  return (
    <div className="grid gap-4 lg:grid-cols-[1fr_360px]">
      <Card title="Datos de la reserva">
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm sm:col-span-2"><span className={label}>Cliente</span>
            <select value={f.client_id} onChange={(e) => set({ client_id: e.target.value, channel: meta.clients.find((c) => c.id === e.target.value)?.channel ?? "" })} className={inputCls} aria-label="Cliente" required>
              <option value="">Seleccione…</option>
              {meta.clients.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
            </select></label>
          <label className="block text-sm"><span className={label}>Canal</span>
            <select value={f.channel} onChange={(e) => set({ channel: e.target.value })} className={inputCls} aria-label="Canal">
              <option value="">Según segmento del cliente</option>
              {meta.channels.map((c) => <option key={c}>{c}</option>)}
            </select></label>
          <label className="block text-sm"><span className={label}>Vendedor</span>
            <select value={f.owner_id} onChange={(e) => set({ owner_id: e.target.value })} className={inputCls} aria-label="Vendedor" disabled={meta.own_only}>
              {!meta.own_only && <option value="">Yo mismo</option>}
              {meta.owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
            </select></label>
          <label className="block text-sm sm:col-span-2"><span className={label}>Producto</span>
            <select value={f.product_id} onChange={(e) => {
              const p = products.find((x) => x.id === e.target.value);
              set({ product_id: e.target.value, rate_id: p?.rates.find((r) => r.segment !== "Niño" && r.channel === (f.channel || "Directo"))?.id ?? p?.rates.find((r) => r.segment !== "Niño")?.id ?? "", departure_id: "", date: "" });
            }} className={inputCls} aria-label="Producto">
              <option value="">Seleccione…</option>
              {products.map((p) => <option key={p.id} value={p.id} disabled={!!p.blocked_reason}>{p.ref} · {p.name}{p.blocked_reason ? ` — ${p.blocked_reason}` : ""}</option>)}
            </select></label>
          {product && (
            <label className="block text-sm sm:col-span-2"><span className={label}>Tarifa</span>
              <select value={f.rate_id} onChange={(e) => set({ rate_id: e.target.value })} className={inputCls} aria-label="Tarifa">
                <option value="">Seleccione…</option>
                {rates.map((r) => <option key={r.id} value={r.id}>{r.ref} · {r.segment} · {r.channel} · {r.currency} {r.price} (hasta {r.valid_to})</option>)}
              </select></label>
          )}
          {product?.by_departure && (
            <label className="block text-sm sm:col-span-2"><span className={label}>Salida</span>
              <select value={f.departure_id} onChange={(e) => set({ departure_id: e.target.value })} className={inputCls} aria-label="Salida">
                <option value="">Seleccione…</option>
                {product.departures.map((d) => (
                  <option key={d.id} value={d.id} disabled={d.status === "cerrada" || d.available < pax}>
                    {d.ref} · {d.date} {d.time} · {d.available} libre(s){d.status === "cerrada" ? " — cerrada" : d.available < pax ? " — sin cupo para el grupo" : ""}
                  </option>
                ))}
              </select></label>
          )}
          {product && !product.by_departure && (
            <label className="block text-sm"><span className={label}>Fecha del servicio</span>
              <input type="date" value={f.date} min={meta.today} onChange={(e) => set({ date: e.target.value })} className={inputCls} aria-label="Fecha del servicio" />
              <span className="mt-1 block text-xs text-slate-500">Anticipación mínima {product.advance_min_days} día(s) · máximo {product.max_pax} pax</span></label>
          )}
          <div className="grid grid-cols-3 gap-2 sm:col-span-2">
            {(["adt", "chd", "inf"] as const).map((k) => (
              <label key={k} className="block text-sm"><span className={label}>{k.toUpperCase()}</span>
                <input type="number" min={0} value={f[k]} onChange={(e) => set({ [k]: Number(e.target.value) } as Partial<typeof f>)} className={inputCls} aria-label={k.toUpperCase()} /></label>
            ))}
          </div>
          <label className="block text-sm sm:col-span-2"><span className={label}>Notas</span>
            <textarea value={f.notes} onChange={(e) => set({ notes: e.target.value })} rows={2} className={inputCls} /></label>
        </div>
      </Card>

      <div className="space-y-3">
        <Card title="Disponibilidad y precio">
          {!preview ? <p className="text-sm text-slate-500">Complete cliente, producto, tarifa, {product?.by_departure ? "salida" : "fecha"} y pasajeros.</p> : (
            <div className="space-y-2 text-sm" data-testid="direct-preview">
              <Notice tone={preview.availability.ok ? (preview.availability.provider_hold ? "warning" : "info") : "danger"}>{preview.availability.message}</Notice>
              {preview.availability.alternatives.length > 0 && <p className="text-xs text-slate-600">Alternativas: {preview.availability.alternatives.map((a) => `${a.ref} (${a.date}, ${a.available} libres)`).join(" · ")}</p>}
              <dl className="grid grid-cols-2 gap-1">
                <dt className="text-slate-500">Adulto</dt><dd className="text-right">{preview.currency} {preview.adult_price}</dd>
                <dt className="text-slate-500">Niño{preview.child_rate ? ` (${preview.child_rate})` : ""}</dt><dd className="text-right">{preview.currency} {preview.child_price}</dd>
                <dt className="text-slate-500">Infante</dt><dd className="text-right">sin cargo</dd>
                {preview.season && <><dt className="text-slate-500">Temporada</dt><dd className="text-right">{preview.season.name} ×{preview.season.factor}</dd></>}
                <dt className="font-medium">Total (sin IGV)</dt><dd className="text-right font-semibold">{preview.currency} {preview.total}</dd>
                <dt className="text-slate-500">Viaje</dt><dd className="text-right">{preview.date}{preview.travel_to !== preview.date ? ` – ${preview.travel_to}` : ""}</dd>
              </dl>
            </div>
          )}
        </Card>
        {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200" role="alert">{error}</p>}
        <div className="flex flex-wrap gap-2">
          <button type="button" disabled={!ready || busy} onClick={() => submit(false)} className={btnGhostCls}>Guardar borrador</button>
          <button type="button" disabled={!ready || busy || !preview?.availability.ok} onClick={() => submit(true)} className={btnCls}
            title={preview && !preview.availability.ok ? "RES-BR-05: sin disponibilidad solo se guarda como borrador" : undefined}>Crear y confirmar</button>
        </div>
        <p className="text-xs text-slate-500">Se crean las CxC 40 % / 60 % y los pasajeros por completar. Con disponibilidad, el borrador también retiene cupo.</p>
      </div>
    </div>
  );
}
