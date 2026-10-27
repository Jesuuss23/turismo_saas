"use client";

import { useState, useTransition } from "react";
import Link from "@/components/Link";
import { callApi } from "@/app/(app)/crm/actions";
import { btnCls, btnGhostCls, inputCls } from "@/components/crm/ui";

type Plan = { id: string; name: string; rank: number; price: number; currency: string; max_users: number | null; max_sedes: number | null; support: string; modules: number };
export type WizardMeta = {
  steps: { n: number; label: string }[]; plans: Plan[]; business_types: string[]; countries: string[]; currencies: string[]; alt_currencies: string[];
  locales: Record<string, string>; timezones: string[]; templates: Record<string, string>; roles: { name: string; label: string; scope: string; required: boolean }[];
  channels: { channel_key: string; label: string; min_plan_id: string; description: string; depends_on_integration: string | null }[];
  tenants: { value: string; label: string }[]; base_domain: string; defaults: Record<string, unknown>;
};
type Values = Record<string, string | string[]>;
type Created = { code: string; trade_name: string; domain: string; invitation_url?: string | null };

const STEP_FIELDS: Record<number, string[]> = {
  1: ["trade_name", "slug", "logo_initials", "country", "custom_domain"], 2: ["legal_name", "ruc", "fiscal_address", "business_type", "legal_rep"], 3: ["plan_id"],
  4: ["sede_name", "sede_city", "sede_address"], 5: ["admin_name", "admin_email", "admin_phone"], 6: ["roles"], 7: ["permission_template", "copy_from_tenant_id"], 8: ["channels", "plan_id"],
  9: ["quote_validity_days", "hold_minutes", "max_discount_pct"], 10: ["currency", "alt_currency", "igv_pct", "fx_rate", "timezone", "locale"], 11: ["status"],
};

/** Asistente de alta de tenant: valida cada paso con `/platform/tenants/validate-step` y crea con Idempotency-Key. */
export function TenantWizard({ meta }: { meta: WizardMeta }) {
  const [step, setStep] = useState(1);
  const [values, setValues] = useState<Values>(() => Object.fromEntries(Object.entries(meta.defaults).map(([k, v]) => [k, Array.isArray(v) ? v.map(String) : String(v)])));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [created, setCreated] = useState<Created | null>(null);
  const [idem] = useState(() => crypto.randomUUID());
  const [pending, start] = useTransition();

  const set = (k: string, v: string | string[]) => setValues((p) => ({ ...p, [k]: v }));
  const str = (k: string) => (typeof values[k] === "string" ? (values[k] as string) : "");
  const list = (k: string) => (Array.isArray(values[k]) ? (values[k] as string[]) : []);
  const toggle = (k: string, v: string) => set(k, list(k).includes(v) ? list(k).filter((x) => x !== v) : [...list(k), v]);
  const payload = (fields?: string[]) => Object.fromEntries(Object.entries(values).filter(([k, v]) => (!fields || fields.includes(k)) && v !== ""));

  const next = () => start(async () => {
    setMessage(null);
    const r = await callApi("/platform/tenants/validate-step", "POST", { step, ...payload(STEP_FIELDS[step]) });
    if (r.error) { setErrors(r.fieldErrors ?? {}); setMessage(r.error); return; }
    setErrors({});
    setStep((s) => Math.min(11, s + 1));
  });
  const submit = () => start(async () => {
    setMessage(null);
    const r = await callApi("/platform/tenants", "POST", payload(), { "Idempotency-Key": idem });
    if (r.error) { setErrors(r.fieldErrors ?? {}); setMessage(r.error); return; }
    setCreated(r.data as Created);
  });

  const field = (k: string, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <label key={k} className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <input {...props} name={k} value={str(k)} onChange={(e) => set(k, e.target.value)} className={inputCls} aria-invalid={!!errors[k]} />
      {errors[k] && <span className="mt-1 block text-xs text-rose-700">{errors[k][0]}</span>}
    </label>
  );
  const select = (k: string, label: string, options: { value: string; label: string }[]) => (
    <label key={k} className="block text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <select name={k} value={str(k)} onChange={(e) => set(k, e.target.value)} className={inputCls}>
        <option value="">Elija…</option>{options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
      {errors[k] && <span className="mt-1 block text-xs text-rose-700">{errors[k][0]}</span>}
    </label>
  );
  const opts = (l: string[]) => l.map((v) => ({ value: v, label: v }));
  const plan = meta.plans.find((p) => p.id === str("plan_id"));
  const rank = plan?.rank ?? 0;

  if (created) {
    return (
      <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-5 text-sm" role="status">
        <p className="text-lg font-semibold text-emerald-900">Tenant {created.code} creado</p>
        <p className="mt-1 text-emerald-900">{created.trade_name} · {created.domain}. Se envió la invitación al administrador.</p>
        {created.invitation_url && <p className="mt-2 break-all text-xs text-slate-700">Enlace de activación (solo entornos de prueba): <a href={created.invitation_url} className="text-sky-800 underline">{created.invitation_url}</a></p>}
        <div className="mt-4 flex gap-2"><Link href={`/admin/tenants?id=${created.code}`} className={btnCls}>Ver tenant</Link><Link href="/admin/tenants" className={btnGhostCls}>Volver a tenants</Link></div>
      </div>
    );
  }

  return (
    <div className="grid gap-5 lg:grid-cols-[14rem_1fr]">
      <ol className="flex gap-1 overflow-x-auto text-sm lg:block lg:space-y-1" aria-label="Pasos">
        {meta.steps.map((s) => (
          <li key={s.n} aria-current={s.n === step ? "step" : undefined}
            className={`shrink-0 whitespace-nowrap rounded px-2 py-1.5 ${s.n === step ? "bg-sky-50 font-medium text-sky-800" : s.n < step ? "text-emerald-800" : "text-slate-500"}`}>
            {s.n < step ? "✓" : `${s.n}.`} {s.label}
          </li>
        ))}
      </ol>
      <div className="min-w-0 rounded-lg border border-slate-200 bg-white p-5">
        <h2 className="mb-4 text-lg font-semibold text-slate-900">Paso {step} de 11 · {meta.steps[step - 1]?.label}</h2>
        <div className="grid gap-3 md:grid-cols-2">
          {step === 1 && <>
            {field("trade_name", "Nombre comercial", { required: true })}
            <div>{field("slug", "Subdominio", { required: true, placeholder: "agencia" })}<p className="mt-1 text-xs text-slate-500">{str("slug") || "subdominio"}.{meta.base_domain}</p></div>
            {field("logo_initials", "Iniciales del logo", { maxLength: 3 })}
            {select("country", "País", opts(meta.countries))}
            {field("custom_domain", "Dominio propio (opcional)")}
          </>}
          {step === 2 && <>
            {field("legal_name", "Razón social", { required: true })}
            {field("ruc", "RUC", { required: true, inputMode: "numeric", maxLength: 11 })}
            {field("fiscal_address", "Domicilio fiscal", { required: true })}
            {select("business_type", "Rubro", opts(meta.business_types))}
            {field("legal_rep", "Representante legal")}
          </>}
          {step === 3 && (
            <fieldset className="grid gap-3 md:col-span-2 md:grid-cols-3">
              <legend className="sr-only">Plan</legend>
              {meta.plans.map((p) => (
                <label key={p.id} className={`cursor-pointer rounded-lg border p-4 text-sm ${str("plan_id") === p.id ? "border-sky-600 ring-2 ring-sky-100" : "border-slate-200"}`}>
                  <input type="radio" name="plan_id" value={p.id} checked={str("plan_id") === p.id} onChange={() => set("plan_id", p.id)} className="sr-only" />
                  <span className="block font-semibold text-slate-900">{p.name}</span>
                  <span className="block text-slate-700">S/ {p.price.toLocaleString("es-PE")} / mes</span>
                  <span className="mt-1 block text-xs text-slate-500">{p.max_users ?? "∞"} usuarios · {p.max_sedes ?? "∞"} sedes · {p.modules} módulos · {p.support}</span>
                </label>
              ))}
            </fieldset>
          )}
          {step === 4 && <>{field("sede_name", "Nombre de la sede", { required: true })}{field("sede_city", "Ciudad", { required: true })}{field("sede_address", "Dirección", { required: true })}</>}
          {step === 5 && <>
            {field("admin_name", "Nombre del administrador", { required: true })}{field("admin_email", "Correo", { required: true, type: "email" })}{field("admin_phone", "Teléfono")}
            <p className="text-xs text-slate-500 md:col-span-2">Recibirá una invitación para definir su contraseña y configurar MFA.</p>
          </>}
          {step === 6 && (
            <fieldset className="grid gap-1 text-sm md:col-span-2 md:grid-cols-2">
              <legend className="mb-2 text-slate-600">Roles de plantilla que se crean en el tenant</legend>
              {meta.roles.map((r) => (
                <label key={r.name} className="flex items-center gap-2">
                  <input type="checkbox" checked={r.required || list("roles").includes(r.name)} disabled={r.required} onChange={() => toggle("roles", r.name)} /> {r.label} <span className="text-xs text-slate-500">{r.scope}</span>
                </label>
              ))}
            </fieldset>
          )}
          {step === 7 && <>
            {select("permission_template", "Matriz de permisos", Object.entries(meta.templates).map(([value, label]) => ({ value, label })))}
            {str("permission_template") === "copiar_tenant" && select("copy_from_tenant_id", "Copiar de", meta.tenants)}
          </>}
          {step === 8 && (
            <fieldset className="grid gap-2 text-sm md:col-span-2">
              <legend className="mb-2 text-slate-600">Canales habilitados (según el plan {plan?.name})</legend>
              {meta.channels.map((c) => {
                const min = meta.plans.find((p) => p.id === c.min_plan_id);
                const allowed = (min?.rank ?? 0) <= rank;
                return (
                  <label key={c.channel_key} className={`flex items-start gap-2 ${allowed ? "" : "text-slate-400"}`}>
                    <input type="checkbox" checked={list("channels").includes(c.channel_key)} onChange={() => toggle("channels", c.channel_key)} />
                    <span>{c.label} <span className="text-xs">· {c.description}{!allowed && ` · requiere plan ${min?.name}`}{c.depends_on_integration && ` · depende de ${c.depends_on_integration}`}</span></span>
                  </label>
                );
              })}
              {errors.channels && <span className="text-xs text-rose-700">{errors.channels[0]}</span>}
            </fieldset>
          )}
          {step === 9 && <>{field("quote_validity_days", "Vigencia de cotización (días)", { type: "number", min: 1 })}{field("hold_minutes", "Hold de cupos (min)", { type: "number", min: 5 })}{field("max_discount_pct", "Descuento máximo sin aprobación (%)", { type: "number", min: 0, max: 30, step: "0.01" })}</>}
          {step === 10 && <>
            {select("currency", "Moneda base", opts(meta.currencies))}{select("alt_currency", "Moneda alterna", opts(meta.alt_currencies))}
            {field("igv_pct", "IGV (%)", { type: "number", step: "0.01" })}{field("fx_rate", "Tipo de cambio", { type: "number", step: "0.0001" })}
            {select("timezone", "Zona horaria", opts(meta.timezones))}{select("locale", "Idioma", Object.entries(meta.locales).map(([value, label]) => ({ value, label })))}
          </>}
          {step === 11 && (
            <div className="space-y-3 text-sm md:col-span-2">
              <dl className="grid grid-cols-[10rem_1fr] gap-1">
                <dt className="text-slate-500">Tenant</dt><dd>{str("trade_name")} · {str("slug")}.{meta.base_domain}</dd>
                <dt className="text-slate-500">Razón social</dt><dd>{str("legal_name")} · RUC {str("ruc")}</dd>
                <dt className="text-slate-500">Plan</dt><dd>{plan?.name}</dd>
                <dt className="text-slate-500">Sede</dt><dd>{str("sede_name")} · {str("sede_city")}</dd>
                <dt className="text-slate-500">Administrador</dt><dd>{str("admin_name")} · {str("admin_email")}</dd>
                <dt className="text-slate-500">Canales</dt><dd>{list("channels").join(", ") || "—"}</dd>
                <dt className="text-slate-500">Moneda</dt><dd>{str("currency")} / {str("alt_currency")} · IGV {str("igv_pct")} % · TC {str("fx_rate")}</dd>
              </dl>
              {select("status", "Estado inicial", [{ value: "prueba", label: "En prueba (14 días)" }, { value: "activo", label: "Activo" }])}
            </div>
          )}
        </div>
        {message && <p role="alert" className="mt-4 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800 ring-1 ring-rose-200">{message}</p>}
        <div className="mt-5 flex flex-wrap gap-2">
          {step > 1 && <button type="button" onClick={() => setStep(step - 1)} className={btnGhostCls} disabled={pending}>Anterior</button>}
          {step < 11
            ? <button type="button" onClick={next} className={btnCls} disabled={pending}>{pending ? "Validando…" : "Siguiente"}</button>
            : <button type="button" onClick={submit} className={btnCls} disabled={pending}>{pending ? "Creando…" : "Crear y activar tenant"}</button>}
        </div>
      </div>
    </div>
  );
}
