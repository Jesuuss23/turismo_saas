"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, inputCls } from "@/components/crm/ui";
import { insertAt, renderSample, VariableChips } from "./TemplateEditor";
import { REASON_LABELS, type Campaign, type Options, type Preview, type Segment } from "@/lib/marketing";

type PromoMode = "none" | "existing" | "new";

/**
 * Editor de campañas (spec 14 §2): canal, segmento, código promocional (existente o nuevo inline), plantilla (las de WhatsApp no
 * aprobadas quedan deshabilitadas), asunto y cuerpo (solo email), audiencia estimada con supresión y vista previa con un contacto elegible.
 */
export function CampaignEditor({ campaign, options, segments, defaultSegment }: { campaign: Campaign | null; options: Options; segments: Segment[]; defaultSegment?: string }) {
  const router = useRouter();
  const [name, setName] = useState(campaign?.name ?? "");
  const [channel, setChannel] = useState<"email" | "whatsapp">(campaign?.channel ?? "email");
  const [segmentCode, setSegmentCode] = useState(campaign?.segment.code ?? defaultSegment ?? segments[0]?.code ?? "");
  const firstTemplate = (ch: string) => options.templates.find((t) => t.channel === ch && t.usable && t.category !== "UTILITY")?.code ?? "";
  const [templateCode, setTemplateCode] = useState(campaign?.template.code ?? firstTemplate("email"));
  const tpl = options.templates.find((t) => t.code === templateCode);
  const [subject, setSubject] = useState(campaign?.subject ?? tpl?.subject ?? "");
  const [body, setBody] = useState(campaign?.body ?? tpl?.body ?? "");
  const [promoMode, setPromoMode] = useState<PromoMode>(campaign?.promo_code ? "existing" : "none");
  const [promo, setPromo] = useState(campaign?.promo_code ?? options.promotions[0]?.code ?? "");
  const [newPromo, setNewPromo] = useState({ code: "", product_id: options.products[0]?.ref ?? "", value: "10", date_to: "" });
  const [preview, setPreview] = useState<Preview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const bodyRef = useRef<HTMLTextAreaElement>(null);
  const segment = segments.find((s) => s.code === segmentCode);

  useEffect(() => {
    if (!segment) return;
    let live = true;
    callApi("/marketing/segments/preview", "POST", { match: segment.match, rules: segment.rules }).then((res) => { if (live && res?.ok) setPreview(res.data as Preview); });
    return () => { live = false; };
  }, [segment]);

  function pickChannel(ch: "email" | "whatsapp") {
    setChannel(ch);
    const code = firstTemplate(ch);
    pickTemplate(code);
  }
  function pickTemplate(code: string) {
    setTemplateCode(code);
    const t = options.templates.find((x) => x.code === code);
    setSubject(t?.subject ?? "");
    setBody(t?.body ?? "");
  }

  const counts = preview?.[channel];
  const sample = preview?.sample.find((c) => (channel === "email" ? c.consent.email === "otorgado" : c.consent.whatsapp === "otorgado")) ?? preview?.sample[0];
  const promoCode = promoMode === "existing" ? promo : promoMode === "new" ? newPromo.code.toUpperCase() : "";
  const vars: Record<string, string> = { nombre: sample?.name.split(" ")[0] ?? "Ana", destino: sample?.destination ?? "Cusco", asesor: "su asesor", codigo_promo: promoCode || "—",
    fecha_viaje: "—", agencia: "la agencia", enlace_baja: "https://…/sitio/baja/…" };
  const text = channel === "email" ? body : tpl?.body ?? "";

  async function save() {
    setBusy(true);
    setError(null);
    const payload: Record<string, unknown> = { name, channel, segment_id: segmentCode, template_id: templateCode, subject: channel === "email" ? subject : null, body: channel === "email" ? body : undefined };
    if (promoMode === "existing") payload.promotion_id = promo;
    if (promoMode === "new") payload.new_promo = { ...newPromo, code: newPromo.code.toUpperCase(), date_to: newPromo.date_to || null };
    if (promoMode === "none") payload.promotion_id = null;
    const res = campaign
      ? await callApi(`/marketing/campaigns/${campaign.code}`, "PUT", payload, { "If-Match": String(campaign.lock_version) })
      : await callApi("/marketing/campaigns", "POST", payload);
    setBusy(false);
    if (res?.error) { setError([res.error, ...Object.values(res.fieldErrors ?? {}).flat()].join(" · ")); return; }
    router.push(`/marketing/campanas/${(res?.data as { code: string }).code}`);
    router.refresh();
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_380px] [&>*]:min-w-0">
      <section className="space-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm">
        <label className="block"><span className="font-medium text-slate-700">Nombre de la campaña</span>
          <input className={inputCls} value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <fieldset className="flex flex-wrap gap-4" disabled={!!campaign}>
          <legend className="mb-1 font-medium text-slate-700">Canal</legend>
          {(["email", "whatsapp"] as const).map((c) => (
            <label key={c} className="flex items-center gap-1"><input type="radio" name="channel" checked={channel === c} onChange={() => pickChannel(c)} /> {c === "email" ? "Email" : "WhatsApp"}</label>
          ))}
        </fieldset>
        <label className="block"><span className="font-medium text-slate-700">Segmento</span>
          <select className={inputCls} value={segmentCode} onChange={(e) => setSegmentCode(e.target.value)}>
            {segments.map((s) => <option key={s.code} value={s.code}>{s.code} · {s.name}</option>)}
          </select>
        </label>
        <fieldset className="space-y-2">
          <legend className="mb-1 font-medium text-slate-700">Código promocional</legend>
          <div className="flex flex-wrap gap-4">
            {([["none", "Sin código"], ["existing", "Código existente"], ["new", "Crear código nuevo"]] as const).map(([k, l]) => (
              <label key={k} className="flex items-center gap-1"><input type="radio" name="promo" checked={promoMode === k} onChange={() => setPromoMode(k)} /> {l}</label>
            ))}
          </div>
          {promoMode === "existing" && (
            <select aria-label="Código existente" className={inputCls} value={promo} onChange={(e) => setPromo(e.target.value)}>
              {options.promotions.map((p) => <option key={p.id} value={p.code}>{p.code} · {p.value} % · {p.product}{p.date_to ? ` · hasta ${p.date_to}` : ""}</option>)}
            </select>
          )}
          {promoMode === "new" && (
            <div className="grid gap-2 sm:grid-cols-2">
              <input aria-label="Nuevo código" className={inputCls} placeholder="CODIGO" value={newPromo.code} onChange={(e) => setNewPromo({ ...newPromo, code: e.target.value })} />
              <select aria-label="Producto del código" className={inputCls} value={newPromo.product_id} onChange={(e) => setNewPromo({ ...newPromo, product_id: e.target.value })}>
                {options.products.map((p) => <option key={p.id} value={p.ref}>{p.ref} · {p.name}</option>)}
              </select>
              <input aria-label="Descuento %" className={inputCls} type="number" min={1} max={100} value={newPromo.value} onChange={(e) => setNewPromo({ ...newPromo, value: e.target.value })} />
              <input aria-label="Vigente hasta" className={inputCls} type="date" value={newPromo.date_to} onChange={(e) => setNewPromo({ ...newPromo, date_to: e.target.value })} />
            </div>
          )}
        </fieldset>
        <label className="block"><span className="font-medium text-slate-700">Plantilla</span>
          <select className={inputCls} value={templateCode} onChange={(e) => pickTemplate(e.target.value)}>
            {options.templates.filter((t) => t.channel === channel).map((t) => (
              <option key={t.code} value={t.code} disabled={!t.usable}>{t.code} · {t.name}{!t.usable ? ` (${t.status_label})` : ""}</option>
            ))}
          </select>
        </label>
        {channel === "email" ? (
          <>
            <label className="block"><span className="font-medium text-slate-700">Asunto</span>
              <input className={inputCls} value={subject} onChange={(e) => setSubject(e.target.value)} />
            </label>
            <VariableChips variables={options.variables} onPick={(v) => insertAt(bodyRef.current, v, body, setBody)} />
            <label className="block"><span className="font-medium text-slate-700">Cuerpo</span>
              <textarea ref={bodyRef} className={`${inputCls} min-h-40 font-mono`} value={body} onChange={(e) => setBody(e.target.value)} />
            </label>
            {!body.includes("{{enlace_baja}}") && <p className="rounded-md bg-amber-50 px-3 py-2 text-amber-900 ring-1 ring-amber-200">El cuerpo debe incluir {"{{enlace_baja}}"} (MKT-BR-02).</p>}
          </>
        ) : (
          <p className="text-xs text-slate-500">En WhatsApp el texto es el de la plantilla aprobada por Meta; no se edita en la campaña.</p>
        )}
        {error && <p className="rounded-md bg-rose-50 px-3 py-2 text-rose-800 ring-1 ring-rose-200">{error}</p>}
        <button type="button" onClick={save} disabled={busy} className={btnCls}>{busy ? "Guardando…" : campaign ? "Guardar cambios" : "Crear campaña (borrador)"}</button>
      </section>

      <aside className="space-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm" aria-live="polite">
        <h2 className="font-semibold text-slate-800">Audiencia estimada</h2>
        {counts ? (
          <>
            <p><span className="text-3xl font-semibold text-slate-900" data-testid="audience-eligible">{counts.eligible}</span> <span className="text-slate-600">elegibles de {preview?.matched} coincidentes</span></p>
            <ul className="space-y-0.5 text-slate-600">
              {Object.entries(REASON_LABELS).map(([k, l]) => <li key={k}>{l}: {counts[k as "optout"]}</li>)}
            </ul>
            <p className="text-xs text-slate-500">Se recalcula al enviar (los consentimientos y la fatiga pueden cambiar).</p>
          </>
        ) : <p className="text-slate-500">Calculando…</p>}
        <h2 className="pt-2 font-semibold text-slate-800">Vista previa</h2>
        <div className="rounded-md bg-slate-50 p-3">
          <p className="mb-1 text-xs text-slate-500">Ejemplo: {sample?.name ?? "—"}</p>
          {channel === "email" && <p className="font-medium text-slate-900">{renderSample(subject, vars)}</p>}
          <p className="whitespace-pre-wrap break-words text-slate-800">{renderSample(text, vars)}</p>
        </div>
      </aside>
    </div>
  );
}
