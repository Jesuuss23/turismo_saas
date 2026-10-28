import Link from "@/components/Link";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Input, Notice, PageHeader, Select, Table, Td, Tabs, TextArea, btnGhostCls, inputCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { dateTime } from "@/lib/crm";
import type { AuditEntry, RuleCenterMeta, RuleCenterRow, RuleOverrides, RuleTemplate } from "@/lib/experience";
import { Simulator, type SimSearch } from "./simulador";

const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";
const TABS = [
  { key: "reglas", label: "Reglas" },
  { key: "simulador", label: "Simulador" },
  { key: "plantillas", label: "Plantillas por tipo de negocio" },
  { key: "historial", label: "Historial de cambios" },
];
const STATE: Record<RuleCenterRow["state"], { tone: "green" | "slate" | "amber" | "rose"; label: string }> = {
  on: { tone: "green", label: "Activa" },
  off_tenant: { tone: "slate", label: "Inactiva" },
  off_plan: { tone: "amber", label: "Fuera del plan" },
  off_dependency: { tone: "rose", label: "Sin efecto" },
};

type Search = SimSearch & { tab?: string; plan?: string; fuera?: string; regla?: string; nueva?: string; restablecer?: string; accion?: string; buscar?: string };

/**
 * Centro de reglas de reserva (spec 16 §2 y spec 17 v2.2): reglas por grupo con rótulo «Desde plan X», candado fuera del plan,
 * vista previa como otro plan (solo visual), excepciones por producto, dependencias, simulador, plantillas por tipo de negocio,
 * historial, reglas personalizadas y restablecer con motivo.
 */
export default async function BookingRulesPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "reglas";
  const planQ = typeof sp.plan === "string" ? sp.plan : "";
  const { data: rules, meta } = await api<{ data: RuleCenterRow[]; meta: RuleCenterMeta }>(`/admin/booking-rules${planQ && tab === "reglas" ? `?plan=${encodeURIComponent(planQ)}` : ""}`);
  const planName = (id: string | null) => meta.plans.find((p) => p.id === id)?.name ?? id ?? "—";
  const base = "/admin/reglas";
  const keep = (extra: Record<string, string>) => `${base}?${new URLSearchParams({ tab, ...(planQ ? { plan: planQ } : {}), ...(sp.fuera ? { fuera: "1" } : {}), ...extra })}`;
  const close = keep({});

  return (
    <section>
      <PageHeader
        title="Reglas de reserva"
        subtitle={<>Reglas del motor de reservas online. Plan actual: <strong>{meta.plan_name}</strong>{meta.template_applied ? <> · plantilla aplicada: {meta.template_applied.name}</> : null}. Apagar una regla no borra la configuración guardada en los productos.</>}
        actions={<>
          <Link href={keep({ nueva: "1" })} className={btnGhostCls} scroll={false}>Nueva regla</Link>
          <Link href={keep({ restablecer: "1" })} className={btnGhostCls} scroll={false}>Restablecer valores por defecto</Link>
        </>}
      />
      <Tabs base={base} tabs={TABS} active={tab} />

      {tab === "reglas" && <RulesTab rules={rules} meta={meta} planQ={planQ} onlyOut={!!sp.fuera} planName={planName} keep={keep} />}
      {tab === "simulador" && <Simulator sp={sp} meta={meta} />}
      {tab === "plantillas" && <TemplatesTab meta={meta} />}
      {tab === "historial" && <HistoryTab sp={sp} rules={rules} />}

      {sp.regla && <OverridesDrawer ruleKey={String(sp.regla)} rule={rules.find((r) => r.key === sp.regla)} close={close} />}
      {sp.nueva && (
        <Drawer title="Nueva regla personalizada" close={close}>
          <ActionForm path="/admin/booking-rules" submitLabel="Crear regla" success="Regla creada (inactiva)" redirectTo={close} className="space-y-3">
            <Input name="rule_key" label="Clave (R-…)" required maxLength={40} placeholder="R-MI-REGLA" />
            <Select name="group_name" label="Grupo" options={meta.groups.map((x) => ({ value: x, label: x }))} required />
            <Input name="name" label="Nombre" required maxLength={120} />
            <TextArea name="description" label="Descripción" required maxLength={2000} />
            <Input name="params" label="Parámetros (nombre=valor, separados por coma)" placeholder="minPax=2, dias=7" />
            <fieldset className="flex flex-wrap gap-3 text-sm"><legend className="mb-1 font-medium text-slate-700">Aplica en</legend>
              {meta.scopes.map((s) => <label key={s} className="flex items-center gap-1"><input type="checkbox" name="scope[]" value={s} defaultChecked={s === "Sitio web"} />{s}</label>)}
            </fieldset>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" name="product_override" value="true" />Admite excepción por producto</label>
            <p className="text-xs text-slate-500">Las reglas propias se crean inactivas. Quedan registradas como banderas: el motor no las evalúa hasta que exista su implementación.</p>
          </ActionForm>
        </Drawer>
      )}
      {sp.restablecer && (
        <Drawer title="Restablecer valores por defecto" close={close}>
          <p className="mb-3 text-sm text-slate-600">Vuelve las reglas del sistema a su estado y parámetros iniciales (activación, parámetros y canales). Las reglas personalizadas y las excepciones por producto se conservan.</p>
          <ActionForm path="/admin/booking-rules/reset" submitLabel="Restablecer" success="Valores por defecto restablecidos" confirm="¿Restablecer todas las reglas del sistema a sus valores por defecto?" redirectTo={close} className="space-y-3">
            <TextArea name="reason" label="Motivo (obligatorio, mín. 5 caracteres)" required minLength={5} maxLength={500} />
          </ActionForm>
        </Drawer>
      )}
    </section>
  );
}

function RulesTab({ rules, meta, planQ, onlyOut, planName, keep }: {
  rules: RuleCenterRow[]; meta: RuleCenterMeta; planQ: string; onlyOut: boolean; planName: (id: string | null) => string; keep: (x: Record<string, string>) => string;
}) {
  const shown = onlyOut ? rules.filter((r) => !r.plan_allows) : rules;
  const groups = meta.groups.filter((g) => shown.some((r) => r.group === g));
  const rank = (id: string) => meta.plans.findIndex((x) => x.id === id);
  const counts = meta.plans.map((p) => ({ ...p, n: rules.filter((r) => r.is_system && rank(r.min_plan) <= rank(p.id)).length }));
  return (
    <div className="space-y-5">
      <form method="get" className="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3 text-sm">
        <input type="hidden" name="tab" value="reglas" />
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">Previsualizar como plan…</span>
          <select name="plan" defaultValue={planQ} className={inputCls}>
            <option value="">Plan actual ({meta.plan_name})</option>
            {meta.plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </label>
        <label className="flex items-center gap-2 pb-2"><input type="checkbox" name="fuera" value="1" defaultChecked={onlyOut} />No incluidas en el plan</label>
        <button className={btnGhostCls}>Aplicar</button>
        <p className="w-full text-xs text-slate-500">Reglas del sistema por plan (DEMO, editable en la consola de plataforma): {counts.map((c) => `${c.name} ${c.n}`).join(" · ")}.</p>
      </form>
      {meta.preview_plan && meta.preview_plan !== meta.plan && (
        <Notice tone="warning">Vista previa como plan <strong>{planName(meta.preview_plan)}</strong> (solo visual): la suscripción no cambia. <Link href="/admin/reglas" className="underline">Volver al plan actual</Link></Notice>
      )}
      {groups.length === 0 && <p className="text-sm text-slate-500">Todas las reglas están incluidas en este plan.</p>}
      {groups.map((g) => (
        <Card key={g} title={g}>
          <ul className="-m-4 divide-y divide-slate-100">
            {shown.filter((r) => r.group === g).map((r) => <RuleItem key={r.key} r={r} planName={planName} rules={rules} keep={keep} scopes={meta.scopes} />)}
          </ul>
        </Card>
      ))}
    </div>
  );
}

function RuleItem({ r, planName, rules, keep, scopes }: { r: RuleCenterRow; planName: (id: string | null) => string; rules: RuleCenterRow[]; keep: (x: Record<string, string>) => string; scopes: string[] }) {
  const names = Object.keys(r.schema?.properties ?? {});
  const path = `/admin/booking-rules/${r.key}`;
  const locked = !r.plan_allows;
  const parent = r.depends_on ? rules.find((x) => x.key === r.depends_on) : null;
  const st = STATE[r.state];
  return (
    <li className="grid gap-2 px-4 py-3 lg:grid-cols-[minmax(0,1fr)_auto]" data-admin-rule={r.key}>
      <div className="min-w-0">
        <p className="flex flex-wrap items-center gap-2">
          <span className="font-medium">{locked && <span aria-label="Bloqueada">🔒 </span>}{r.name}</span>
          <span className="font-mono text-[11px] text-slate-500">{r.key}</span>
          <Pill tone={st.tone}>{st.label}</Pill>
          {r.is_system && <Pill tone="sky">Desde {planName(r.min_plan)}</Pill>}
          {r.is_custom && <Pill tone="violet">{r.evaluated ? "propia" : "propia · no evaluada"}</Pill>}
          {r.product_override
            ? <Link href={keep({ regla: r.key })} scroll={false} className="text-xs text-sky-800 underline">Por producto · {r.overrides} excepción(es)</Link>
            : <Pill>Solo tenant</Pill>}
        </p>
        <p className="mt-1 text-sm text-slate-600">{r.description}</p>
        {r.reads && <p className="mt-1 text-xs text-slate-500">El motor lee: {r.reads}</p>}
        {locked && (
          <p className="mt-1 text-xs text-amber-800">Disponible desde el plan {planName(r.min_plan)}. <Link href="/admin/suscripcion" className="font-medium underline">Mejorar plan</Link></p>
        )}
        {r.depends_on && !r.depends_ok && (
          <p className="mt-1 text-xs text-rose-700">Sin efecto mientras {parent?.name ?? r.depends_on} ({r.depends_on}) esté inactiva.</p>
        )}
        <div className="mt-2 flex flex-wrap items-end gap-3">
          {names.length > 0 && (
            <ActionForm path={path} method="PATCH" ifMatch={r.lock_version} submitLabel="Guardar parámetros" submitClass={small} success="Parámetros guardados" className="flex flex-wrap items-end gap-2">
              {names.map((n) => (
                <label key={n} className="text-xs">{n}
                  <input name={`params.${n}`} type="number" step="any" min={r.schema.properties![n].minimum} max={r.schema.properties![n].maximum} defaultValue={r.params[n]} className="ml-1 w-20 rounded border border-slate-300 px-1 py-0.5" />
                </label>
              ))}
            </ActionForm>
          )}
          <ActionForm path={path} method="PATCH" ifMatch={r.lock_version} submitLabel="Guardar canales" submitClass={small} success="Canales guardados" className="flex flex-wrap items-center gap-2">
            <span className="text-xs text-slate-500">Aplica en:</span>
            {scopes.map((s) => (
              <label key={s} className="flex items-center gap-1 text-xs"><input type="checkbox" name="scope[]" value={s} defaultChecked={r.scope.includes(s)} />{s}</label>
            ))}
          </ActionForm>
        </div>
      </div>
      <div className="flex items-start gap-2">
        <ActionForm path={path} method="PATCH" ifMatch={r.lock_version} submitLabel={r.enabled ? "Desactivar" : "Activar"} submitClass={small} success={r.enabled ? "Regla desactivada" : "Regla activada"} inline
          disabled={locked && !r.enabled} disabledReason={`Disponible desde el plan ${planName(r.min_plan)}`}>
          <input type="hidden" name="enabled" value={r.enabled ? "false" : "true"} />
        </ActionForm>
        {r.is_custom && (
          <ActionForm path={path} method="DELETE" submitLabel="Eliminar" submitClass={small} success="Regla eliminada" confirm={`¿Eliminar la regla ${r.key}?`} inline />
        )}
      </div>
    </li>
  );
}

async function OverridesDrawer({ ruleKey, rule, close }: { ruleKey: string; rule?: RuleCenterRow; close: string }) {
  if (!rule) return null;
  const { data } = await api<{ data: RuleOverrides }>(`/admin/booking-rules/${encodeURIComponent(ruleKey)}/overrides`);
  return (
    <Drawer title={`Excepciones por producto · ${rule.name}`} close={close}>
      <p className="mb-3 text-sm text-slate-600">Regla del tenant: <strong>{rule.enabled ? "activa" : "inactiva"}</strong>. Una excepción apaga la regla en un producto o cambia sus parámetros; no activa una regla inactiva del tenant (CR-02).</p>
      <h3 className="mb-2 text-sm font-semibold">Productos con excepción ({data.items.length})</h3>
      <ul className="mb-5 divide-y divide-slate-100 rounded-md border border-slate-200">
        {data.items.length === 0 && <li className="px-3 py-2 text-sm text-slate-500">Ningún producto sobrescribe esta regla.</li>}
        {data.items.map((o) => (
          <li key={o.product_id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
            <span>
              <Link href={`/catalogo/productos/${o.ref}/reservas`} className="font-medium text-sky-800 hover:underline">{o.ref}</Link> {o.name}
              <span className="ml-2 text-xs text-slate-500">{o.enabled === false ? "no aplica" : o.enabled === true ? "aplica" : "hereda"}{Object.keys(o.params).length ? ` · ${JSON.stringify(o.params)}` : ""}</span>
            </span>
            <ActionForm path={`/catalog/products/${o.ref}/rule-overrides/${ruleKey}`} method="DELETE" submitLabel="Quitar" submitClass={small} success="Excepción quitada" inline />
          </li>
        ))}
      </ul>
      <h3 className="mb-2 text-sm font-semibold">Agregar excepción</h3>
      <ul className="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-md border border-slate-200">
        {data.candidates.map((c) => (
          <li key={c.product_id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
            <span className="min-w-0 truncate"><span className="font-mono text-xs text-slate-500">{c.ref}</span> {c.name}</span>
            <ActionForm path={`/catalog/products/${c.ref}/rule-overrides/${ruleKey}`} method="PUT" submitLabel="No aplicar aquí" submitClass={small} success="Excepción agregada" inline>
              <input type="hidden" name="enabled" value="false" />
            </ActionForm>
          </li>
        ))}
      </ul>
    </Drawer>
  );
}

async function TemplatesTab({ meta }: { meta: RuleCenterMeta }) {
  const { data } = await api<{ data: RuleTemplate[] }>("/admin/booking-rules/templates");
  return (
    <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
      {data.map((t) => (
        <Card key={t.code} title={<>{t.name}{meta.template_applied?.code === t.code && <span className="ml-2"><Pill tone="green">Aplicada</Pill></span>}</>}>
          <div data-template={t.code}>
            <p className="text-sm text-slate-600">{t.description}</p>
            <p className="mt-2 text-xs text-slate-500">{t.enabled_codes.length} reglas activas en esta plantilla.</p>
            <div className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
              <div>
                <p className="font-medium text-emerald-800">+ Se activan ({t.enable.length})</p>
                <ul className="mt-1 space-y-0.5 text-xs">{t.enable.length ? t.enable.map((x) => <li key={x.key}>+ {x.name}{!x.plan_allows && <span className="text-amber-700"> (fuera del plan: queda inactiva)</span>}</li>) : <li className="text-slate-500">Sin cambios</li>}</ul>
              </div>
              <div>
                <p className="font-medium text-rose-800">− Se desactivan ({t.disable.length})</p>
                <ul className="mt-1 space-y-0.5 text-xs">{t.disable.length ? t.disable.map((x) => <li key={x.key}>− {x.name}</li>) : <li className="text-slate-500">Sin cambios</li>}</ul>
              </div>
            </div>
            <div className="mt-3">
              <ActionForm path="/admin/booking-rules/apply-template" submitLabel={`Aplicar «${t.name}»`} submitClass={small} success="Plantilla aplicada"
                confirm={`Se activarán ${t.enable.length} y se desactivarán ${t.disable.length} regla(s). ¿Aplicar la plantilla «${t.name}»?`} inline>
                <input type="hidden" name="template" value={t.code} />
              </ActionForm>
            </div>
          </div>
        </Card>
      ))}
    </div>
  );
}

async function HistoryTab({ sp, rules }: { sp: Search; rules: RuleCenterRow[] }) {
  const q = new URLSearchParams();
  if (sp.buscar) q.set("rule", sp.buscar);
  if (sp.accion) q.set("action", sp.accion);
  const { data } = await api<{ data: AuditEntry[] }>(`/admin/booking-rules/audit?${q}`);
  const actions = ["activar", "desactivar", "editar_parametros", "modificar", "crear", "eliminar", "aplicar_plantilla", "restablecer"];
  return (
    <div className="space-y-3">
      <form method="get" className="flex flex-wrap items-end gap-3 text-sm">
        <input type="hidden" name="tab" value="historial" />
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Regla</span>
          <select name="buscar" defaultValue={sp.buscar ?? ""} className={inputCls}>
            <option value="">Todas</option>
            {rules.map((r) => <option key={r.key} value={r.key}>{r.key} · {r.name}</option>)}
          </select>
        </label>
        <label className="block"><span className="mb-1 block font-medium text-slate-700">Acción</span>
          <select name="accion" defaultValue={sp.accion ?? ""} className={inputCls}>
            <option value="">Todas</option>
            {actions.map((a) => <option key={a} value={a}>{a.replaceAll("_", " ")}</option>)}
          </select>
        </label>
        <button className={btnGhostCls}>Filtrar</button>
      </form>
      <Table head={["Fecha", "Entidad", "Acción", "Antes", "Después", "Rol"]} empty="Sin cambios registrados">
        {data.map((e) => (
          <tr key={e.id}>
            <Td className="whitespace-nowrap">{dateTime(e.at)}</Td>
            <Td>{e.entity} <span className="font-mono text-xs">{e.entity_id}</span></Td>
            <Td>{e.action.replaceAll("_", " ")}</Td>
            <Td className="max-w-xs break-words text-xs text-slate-600">{e.before ?? "—"}</Td>
            <Td className="max-w-xs break-words text-xs text-slate-600">{e.after ?? "—"}</Td>
            <Td className="text-xs">{e.actor ?? "Sistema"}</Td>
          </tr>
        ))}
      </Table>
    </div>
  );
}
