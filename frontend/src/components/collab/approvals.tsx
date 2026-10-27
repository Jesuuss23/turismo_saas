import { Fragment } from "react";
import Link from "@/components/Link";
import { api, ApiError } from "@/lib/api";
import { date, dateTime, qs } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Drawer, Kpis, Notice, PageHeader, Table, Td, TextArea, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { NewApprovalForm } from "@/components/collab/NewApprovalForm";

export type ApprovalRow = {
  id: string; code: string; type: string; type_label: string; is_generic: boolean; title: string; subject_code: string | null; subject_version: number | null; link: string | null;
  requester: string; requester_id: string | null; requester_kind: string; created_at: string; amount: string; currency: string; amount_base: string;
  status: string; status_label: string; closed_in_origin: boolean; current_level: number; total_levels: number; round: number; due_at: string | null;
  sla: "ok" | "warning" | "overdue" | "paused" | null; delegated_to: string | null; decided_by: string | null; decided_at: string | null; decision_note: string | null; lock_version: number; mine: boolean;
};
type Person = { id: string; name: string };
type Detail = ApprovalRow & {
  justification: string | null;
  flow: { level: number; rule: string; permission: string; min_amount_pen: string; sla_hours: number; state: string; decided_by: string | null; decided_at: string | null; note: string | null }[];
  rule: { code: string; permission: string; sla_hours: number; forbid_self_approval: boolean } | null;
  eligible: Person[]; delegates: Person[];
  steps: { level: number; decision: string; by: string | null; at: string; note: string | null; rule: string | null }[];
  info_requests: { id: string; question: string; asked_by: string | null; asked_at: string; answer: string | null; answered_by: string | null; answered_at: string | null }[];
  events: { event: string; label: string; actor: string; note: string | null; at: string }[];
  effect: string; origin: Record<string, unknown> | null; snapshot: Record<string, unknown> | null;
  actions: { approve: boolean; reject: boolean; ask_info: boolean; delegate: boolean; answer: string | null; withdraw: boolean; resubmit: boolean; blocked_reason: string | null; needs_override: boolean };
};
type ListMeta = {
  box: string; counters: { mine: number; overdue: number; sent_open: number; resolved_7d: number; avg_hours: number | null };
  types: Record<string, string>; statuses: Record<string, string>; scope: string; can_manage_rules: boolean;
  decisions: { code: string; action: string; after: string | null; actor: string; at: string }[];
};
export type ApprovalParams = { tab?: string; type?: string; status?: string; sla?: string; nueva?: string };

const TABS = [
  { key: "mine", label: "Pendientes para mí" },
  { key: "sent", label: "Enviadas por mí" },
  { key: "all", label: "Todas (según rol)" },
  { key: "history", label: "Historial" },
];
const STATUS_TONE: Record<string, "amber" | "sky" | "green" | "rose" | "slate"> = { pendiente: "amber", info: "sky", aprobada: "green", rechazada: "rose", anulada: "slate" };
const SLA: Record<string, { tone: "green" | "amber" | "rose" | "slate"; label: string }> = {
  ok: { tone: "green", label: "En plazo" }, warning: { tone: "amber", label: "Por vencer" }, overdue: { tone: "rose", label: "Vencido" }, paused: { tone: "slate", label: "En pausa (consulta)" },
};
const FLOW_STATE: Record<string, string> = { pendiente: "Pendiente", info: "Consulta abierta", en_espera: "En espera", aprobada: "Aprobado", rechazada: "Rechazado", no_aplica: "No aplica" };
const DECISION_LABEL: Record<string, string> = { aprobar: "aprobó", rechazar: "rechazó", retirar: "retiró", cerrar_en_origen: "cerró en el origen" };
const rejectCls = "rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800 disabled:cursor-not-allowed disabled:opacity-50";

export function amount(value: string | number | null | undefined, currency = "PEN") {
  if (value === null || value === undefined || Number(value) === 0) return "—";
  return new Intl.NumberFormat("es-PE", { style: "currency", currency, maximumFractionDigits: 2 }).format(Number(value));
}

export function SlaPill({ sla, due }: { sla: ApprovalRow["sla"]; due?: string | null }) {
  if (!sla) return <span className="text-slate-400">—</span>;
  return <span title={due ? `Vence ${dateTime(due)}` : undefined}><Pill tone={SLA[sla].tone}>{SLA[sla].label}</Pill></span>;
}

/** Detalle de la solicitud: `null` si no es visible para el rol (APR-SCOPE). */
async function fetchDetail(code: string): Promise<Detail | null> {
  try {
    return (await api<{ data: Detail }>(`/approvals/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && (e.status === 403 || e.status === 404)) return null;
    throw e;
  }
}

/** Bandeja de aprobaciones unificada (spec 10 §2): KPIs, pestañas, tabla y el detalle como drawer sobre la lista. */
export async function ApprovalsView({ sp, code }: { sp: ApprovalParams; code?: string }) {
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "mine";
  const filters = { "filter[box]": tab, "filter[type]": sp.type, "filter[status]": sp.status, "filter[sla]": sp.sla };
  const [{ data, meta }, detail] = await Promise.all([
    api<{ data: ApprovalRow[]; meta: ListMeta }>(`/approvals${qs(filters)}`),
    code ? fetchDetail(code) : Promise.resolve(null),
  ]);
  const query = qs({ tab, type: sp.type, status: sp.status, sla: sp.sla });
  const back = `/aprobaciones${query}`;
  const href = (c: string) => `/aprobaciones/${c}${query}`;
  const k = meta.counters;

  return (
    <section>
      <PageHeader title="Bandeja de aprobaciones" subtitle={meta.scope}
        actions={<Link href={`${back}${back.includes("?") ? "&" : "?"}nueva=1`} className={btnCls}>Nueva solicitud</Link>} />
      <Kpis items={[
        { label: "Pendientes para mí", value: k.mine, hint: "Puede decidirlas ahora" },
        { label: "SLA vencido", value: k.overdue, hint: k.overdue ? "Escalado a Gerencia" : "Sin vencimientos" },
        { label: "Enviadas por mí", value: k.sent_open, hint: "En curso" },
        { label: "Resueltas 7 días", value: k.resolved_7d, hint: k.avg_hours !== null ? `Tiempo medio ${k.avg_hours} h` : "Sin decisiones" },
      ]} />
      <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Bandejas">
        {TABS.map((t) => (
          <Link key={t.key} href={`/aprobaciones?tab=${t.key}`} aria-current={t.key === tab ? "page" : undefined}
            className={`-mb-px border-b-2 px-3 py-2 ${t.key === tab ? "border-sky-700 font-medium text-sky-800" : "border-transparent text-slate-600 hover:text-slate-900"}`}>
            {t.label}{t.key === "mine" && k.mine ? ` (${k.mine})` : ""}
          </Link>
        ))}
        <Link href="/aprobaciones/reglas" className="-mb-px border-b-2 border-transparent px-3 py-2 text-slate-600 hover:text-slate-900">Reglas de aprobación</Link>
      </nav>

      <form className="mb-4 grid grid-cols-2 gap-2 md:grid-cols-5" method="get" action="/aprobaciones">
        <input type="hidden" name="tab" value={tab} />
        <select name="type" defaultValue={sp.type ?? ""} aria-label="Tipo" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todos los tipos</option>{Object.entries(meta.types).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        {tab !== "mine" && (
          <select name="status" defaultValue={sp.status ?? ""} aria-label="Estado" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
            <option value="">Todos los estados</option>{Object.entries(meta.statuses).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        )}
        <select name="sla" defaultValue={sp.sla ?? ""} aria-label="SLA" className="rounded-md border border-slate-300 px-2 py-2 text-sm">
          <option value="">Todo SLA</option>{Object.entries(SLA).map(([v, s]) => <option key={v} value={v}>{s.label}</option>)}
        </select>
        <button className={btnGhostCls}>Filtrar</button>
      </form>

      <Table head={["Solicitud", "Tipo", "Solicitante", "Monto / impacto", "SLA", "Estado", "Nivel"]}
        empty={tab === "mine" ? "No tiene solicitudes pendientes de su decisión." : "Sin solicitudes."}>
        {data.map((r) => (
          <tr key={r.id} data-code={r.code}>
            <Td>
              <Link href={href(r.code)} scroll={false} className="font-mono text-sky-800 hover:underline">{r.code}</Link>
              <span className="block max-w-xs text-xs text-slate-600 [overflow-wrap:anywhere]">{r.title}</span>
            </Td>
            <Td>{r.type_label}</Td>
            <Td>{r.requester}<span className="block text-xs text-slate-500">{dateTime(r.created_at)}</span></Td>
            <Td className="whitespace-nowrap">{amount(r.amount, r.currency)}</Td>
            <Td><SlaPill sla={r.sla} due={r.due_at} /></Td>
            <Td><Pill tone={STATUS_TONE[r.status]}>{r.status_label}</Pill>{r.closed_in_origin && <span className="block text-xs text-slate-500">Cerrada en origen</span>}</Td>
            <Td className="whitespace-nowrap">{r.current_level}/{r.total_levels}{r.round > 1 && <span className="block text-xs text-slate-500">Ronda {r.round}</span>}</Td>
          </tr>
        ))}
      </Table>

      {tab === "history" && meta.decisions.length > 0 && (
        <Card title="Últimas decisiones auditadas" className="mt-4">
          <ul className="space-y-1 text-sm">
            {meta.decisions.map((d, i) => (
              <li key={i}><Link href={href(d.code)} scroll={false} className="font-mono text-sky-800 hover:underline">{d.code}</Link> · {d.actor} {DECISION_LABEL[d.action] ?? d.action}
                <span className="text-xs text-slate-500"> · {dateTime(d.at)}</span></li>
            ))}
          </ul>
        </Card>
      )}

      {sp.nueva && (
        <Drawer title="Nueva solicitud" close={back}>
          <NewApprovalForm meta={(await api<{ data: NewMeta }>("/approvals/meta")).data} />
        </Drawer>
      )}

      {code && (
        <Drawer title={detail ? `${detail.code} · ${detail.type_label}` : code} close={back}>
          {detail ? <ApprovalDetail d={detail} /> : (
            <Notice tone="warning">Esta solicitud no está dentro de su alcance: solo ve sus solicitudes, las delegadas y los tipos que puede aprobar.</Notice>
          )}
        </Drawer>
      )}
    </section>
  );
}

export type NewMeta = {
  generic: Record<string, string>;
  agencies: { code: string; name: string; currency: string; status: string; available: string }[];
  reservations: { code: string; title: string; currency: string }[];
};

function ApprovalDetail({ d }: { d: Detail }) {
  const a = d.actions;
  const live = d.status === "pendiente" || d.status === "info";
  const base = `/approvals/${d.code}`;
  const openInfo = d.info_requests.find((i) => !i.answered_at);

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <Pill tone={STATUS_TONE[d.status]}>{d.status_label}</Pill>
        <Pill tone="sky">Nivel {d.current_level} de {d.total_levels}</Pill>
        {live && <SlaPill sla={d.sla} due={d.due_at} />}
        {d.round > 1 && <Pill>Ronda {d.round}</Pill>}
      </div>
      <p className="font-medium text-slate-900 [overflow-wrap:anywhere]">{d.title}</p>
      {d.closed_in_origin && <Notice>Se cerró en el módulo de origen; la bandeja solo conserva la trazabilidad.</Notice>}
      {a.blocked_reason && <Notice tone="warning">{a.blocked_reason}</Notice>}
      {d.status === "info" && openInfo && <Notice>Consulta abierta: el SLA está en pausa hasta que el solicitante responda.</Notice>}

      <ol className="flex flex-wrap gap-2 text-xs" aria-label="Flujo de aprobación">
        {d.flow.map((f) => (
          <li key={f.level} className={`rounded-md px-3 py-1.5 ring-1 ${f.state === "aprobada" ? "bg-emerald-50 text-emerald-900 ring-emerald-200" : f.state === "rechazada" ? "bg-rose-50 text-rose-900 ring-rose-200" : f.state === "pendiente" || f.state === "info" ? "bg-amber-50 text-amber-900 ring-amber-200" : "bg-slate-50 text-slate-600 ring-slate-200"}`}>
            <span className="font-medium">Nivel {f.level} · {FLOW_STATE[f.state] ?? f.state}</span>
            <span className="block">{f.permission} · desde {amount(f.min_amount_pen) === "—" ? "S/ 0" : amount(f.min_amount_pen)} · {f.sla_hours} h</span>
            {f.decided_by && <span className="block">{f.decided_by} · {dateTime(f.decided_at)}</span>}
          </li>
        ))}
      </ol>

      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-sm">
        <dt className="text-slate-500">Origen</dt>
        <dd className="[overflow-wrap:anywhere]">{d.link ? <Link href={d.link} className="text-sky-800 hover:underline">{d.subject_code ?? "Ver registro"}{d.subject_version ? ` v${d.subject_version}` : ""}</Link> : (d.subject_code ?? "Solicitud genérica")}</dd>
        <dt className="text-slate-500">Solicitante</dt><dd>{d.requester}{d.requester_kind !== "user" && <span className="text-xs text-slate-500"> ({d.requester_kind === "supplier" ? "proveedor" : "sistema"})</span>}</dd>
        <dt className="text-slate-500">Fecha</dt><dd>{dateTime(d.created_at)}</dd>
        <dt className="text-slate-500">Monto</dt><dd>{amount(d.amount, d.currency)}{d.currency !== "PEN" && Number(d.amount) > 0 && <span className="text-xs text-slate-500"> · {amount(d.amount_base)}</span>}</dd>
        {live && <><dt className="text-slate-500">Vence</dt><dd>{d.sla === "paused" ? "En pausa" : dateTime(d.due_at)}</dd></>}
        {d.rule && <><dt className="text-slate-500">Regla</dt><dd>{d.rule.code} · {d.rule.permission} · SLA {d.rule.sla_hours} h{d.rule.forbid_self_approval ? " · sin autoaprobación" : ""}</dd></>}
        {live && <><dt className="text-slate-500">Aprobadores</dt><dd>{d.eligible.map((u) => u.name).join(", ") || "Ningún usuario activo con el permiso"}</dd></>}
        {d.delegated_to && <><dt className="text-slate-500">Delegada a</dt><dd>{d.delegated_to}</dd></>}
        {d.decided_by && <><dt className="text-slate-500">Decidió</dt><dd>{d.decided_by} · {dateTime(d.decided_at)}</dd></>}
        {d.decision_note && <><dt className="text-slate-500">Motivo</dt><dd>{d.decision_note}</dd></>}
      </dl>

      {d.justification && <Card title="Justificación"><p className="whitespace-pre-line text-sm text-slate-700">{d.justification}</p></Card>}
      {d.origin && <Card title="Registro de origen"><Origin o={d.origin} /></Card>}

      {d.info_requests.length > 0 && (
        <Card title="Consultas">
          <ul className="space-y-3 text-sm">
            {d.info_requests.map((i) => (
              <li key={i.id}>
                <p className="text-slate-800"><span className="font-medium">{i.asked_by}:</span> {i.question}</p>
                <p className="text-xs text-slate-500">{dateTime(i.asked_at)}</p>
                {i.answer ? <p className="mt-1 border-l-2 border-sky-200 pl-2 text-slate-700"><span className="font-medium">{i.answered_by}:</span> {i.answer}</p>
                  : <p className="mt-1 text-xs text-amber-800">Sin respuesta</p>}
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Notice>Al aprobar: {d.effect}</Notice>

      {a.needs_override && <Notice tone="warning">El descuento supera el tope de política: solo puede aprobarlo quien tenga quotes.discount_override (APR-BR-05).</Notice>}
      {(a.approve || a.reject) && (
        <div className="grid gap-3">
          <Card title="Decidir">
            <div className="grid gap-3">
              <ActionForm path={`${base}/approve`} ifMatch={d.lock_version} idempotent submitLabel={d.current_level < d.total_levels ? `Aprobar nivel ${d.current_level}` : "Aprobar"}
                success="Solicitud aprobada" disabled={a.needs_override} disabledReason="Requiere quotes.discount_override">
                <TextArea name="note" label="Comentario (opcional)" rows={2} />
              </ActionForm>
              <ActionForm path={`${base}/reject`} ifMatch={d.lock_version} idempotent submitLabel="Rechazar" submitClass={rejectCls} success="Solicitud rechazada">
                <TextArea name="note" label="Motivo del rechazo (obligatorio)" rows={2} required />
              </ActionForm>
            </div>
          </Card>
          {a.ask_info && (
            <Card title="Pedir información">
              <ActionForm path={`${base}/info-requests`} ifMatch={d.lock_version} submitLabel="Enviar consulta" submitClass={btnGhostCls} success="Consulta enviada: el SLA queda en pausa">
                <TextArea name="question" label="Pregunta al solicitante" rows={2} required />
              </ActionForm>
            </Card>
          )}
          {a.delegate && d.delegates.length > 0 && (
            <Card title="Delegar">
              <ActionForm path={`${base}/delegate`} ifMatch={d.lock_version} submitLabel="Delegar" submitClass={btnGhostCls} success="Solicitud delegada">
                <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Delegar en</span>
                  <select name="user_id" required className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Elija un aprobador con el mismo permiso</option>{d.delegates.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                  </select>
                </label>
              </ActionForm>
            </Card>
          )}
        </div>
      )}
      {a.answer && (
        <Card title="Responder consulta">
          <ActionForm path={`${base}/info-requests/${a.answer}/answer`} submitLabel="Responder" success="Respuesta enviada: la solicitud vuelve al aprobador">
            <TextArea name="answer" label="Respuesta" rows={3} required />
          </ActionForm>
        </Card>
      )}
      {a.resubmit && (
        <Card title="Reenviar a aprobación">
          <ActionForm path={`${base}/resubmit`} idempotent submitLabel="Reenviar a aprobación" success="Reenviada en una nueva ronda">
            <TextArea name="note" label="Qué se corrigió" rows={2} required />
          </ActionForm>
        </Card>
      )}
      {a.withdraw && (
        <ActionForm path={`${base}/withdraw`} ifMatch={d.lock_version} submitLabel="Retirar solicitud" submitClass={btnGhostCls} success="Solicitud retirada"
          confirm="¿Retirar la solicitud? Quedará anulada y el registro de origen vuelve a su estado anterior." />
      )}

      <Card title="Trazabilidad">
        <ol className="space-y-2 text-sm">
          {d.events.map((e, i) => (
            <li key={i} className="border-l-2 border-slate-200 pl-3">
              <p className="font-medium text-slate-800">{e.label}</p>
              {e.note && <p className="text-slate-600 [overflow-wrap:anywhere]">{e.note}</p>}
              <p className="text-xs text-slate-500">{e.actor} · {dateTime(e.at)}</p>
            </li>
          ))}
        </ol>
      </Card>
    </div>
  );
}

const ORIGIN_LABELS: Record<string, string> = {
  status: "Estado", client: "Cliente", owner: "Dueño", reasons: "Motivos", discount_pct: "Descuento %", net_total: "Neto", grand_total: "Total", margin_pct: "Margen %",
  cost_total: "Costo", travel: "Viaje", reason: "Motivo", request_status: "Solicitud", reservation: "Reserva", amount: "Monto", paid_on: "Pagado el", reference: "Referencia",
  valid: "Reversible", provider: "Proveedor", due_date: "Vence", kind_label: "Tipo", cost_type: "Tipo de costo", round: "Ronda", observation: "Observación",
  rule: "Regla", base: "Base", pct: "%", service: "Servicio", current_cost: "Costo actual", proposed_cost: "Costo propuesto", effective_from: "Vigente desde",
  variation_pct: "Variación %", credit_limit: "Línea actual", credit_used: "Consumido", available: "Disponible", limit_after: "Línea tras aprobar", block_reason: "Bloqueo",
  total: "Total", paid: "Pagado", currency: "Moneda", policy_state: "Política", warnings: "Alertas",
};
const HIDDEN = new Set(["kind", "title", "lines", "show_margin", "impact", "receivable", "over_cap", "approved"]);

function show(v: unknown): string {
  if (v === null || v === undefined || v === "") return "—";
  if (typeof v === "boolean") return v ? "Sí" : "No";
  if (Array.isArray(v)) return v.length ? v.join(" · ") : "—";
  return String(v);
}

/** Detalle del registro de origen (líneas y margen de la cotización, impacto de la cancelación, saldo de la CxC…). */
function Origin({ o }: { o: Record<string, unknown> }) {
  const lines = o.lines as { line: number; product: string; date: string; qty: number; unit_price: string; sale_total: string; cost_total?: string }[] | undefined;
  const impact = o.impact as { currency: string; total: string; paid: string; penalty: string; refund: string; owed: string; effects: string[] } | undefined;
  const receivable = o.receivable as { code: string; balance: string; status: string } | undefined;
  const entries = Object.entries(o).filter(([key, v]) => !HIDDEN.has(key) && v !== null && !(Array.isArray(v) && !v.length));
  return (
    <div className="space-y-3 text-sm">
      <p className="font-medium text-slate-800 [overflow-wrap:anywhere]">{String(o.title ?? "")}</p>
      <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
        {entries.map(([key, v]) => <Fragment key={key}><dt className="text-slate-500">{ORIGIN_LABELS[key] ?? key}</dt><dd className="[overflow-wrap:anywhere]">{show(v)}</dd></Fragment>)}
        {receivable && <><dt className="text-slate-500">CxC</dt><dd>{receivable.code} · saldo {receivable.balance} · {receivable.status}</dd></>}
      </dl>
      {lines && (
        <div className="overflow-x-auto">
          <table className="min-w-full text-xs">
            <thead className="text-left text-slate-500"><tr><th className="py-1 pr-2">#</th><th className="pr-2">Producto</th><th className="pr-2">Fecha</th><th className="pr-2 text-right">Venta</th>{o.show_margin ? <th className="text-right">Costo</th> : null}</tr></thead>
            <tbody className="divide-y divide-slate-100">
              {lines.map((l) => (
                <tr key={l.line}><td className="py-1 pr-2">{l.line}</td><td className="pr-2">{l.product}</td><td className="whitespace-nowrap pr-2">{date(l.date)}</td>
                  <td className="pr-2 text-right">{l.sale_total}</td>{o.show_margin ? <td className="text-right">{l.cost_total}</td> : null}</tr>
              ))}
            </tbody>
          </table>
          {!o.show_margin && <p className="mt-1 text-xs text-slate-500">Costos y margen ocultos para su rol.</p>}
        </div>
      )}
      {impact && (
        <div className="rounded-md bg-slate-50 p-3">
          <p className="font-medium text-slate-800">Impacto de la cancelación ({impact.currency})</p>
          <p className="text-slate-700">Total {impact.total} · pagado {impact.paid} · penalidad {impact.penalty} · reembolso {impact.refund} · saldo {impact.owed}</p>
          <ul className="mt-1 list-disc pl-5 text-slate-600">{impact.effects.map((e) => <li key={e}>{e}</li>)}</ul>
        </div>
      )}
    </div>
  );
}
