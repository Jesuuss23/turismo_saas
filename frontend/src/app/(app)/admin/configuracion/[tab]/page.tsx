import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Notice, PageHeader, Select, Table, Td, TextArea } from "@/components/crm/ui";
import { Pill, RouteTabs } from "@/components/admin/ui";

type Section = { values: Record<string, string | number | boolean | null>; lock_version: number };
type Settings = {
  sections: Record<"general" | "currency" | "policies" | "operations" | "channels", Section>;
  tenant: { code: string; ruc: string; country: string; plan: string; plan_id: string };
  base_currency_locked: boolean;
  channels: { key: string; label: string; description: string; min_plan: string; editable: boolean; integration: string | null; integration_status: string | null }[];
  sedes: { id: string; code: string; name: string }[];
  options: { fx_sources: string[]; roundings: string[]; round_modes: Record<string, string>; timezones: string[]; langs: Record<string, string>; date_formats: string[]; currencies: string[]; alt_currencies: string[] };
};
type Sequence = { doc_type: string; label: string; series: string; prefix: string; pad_width: number; next_number: number; next_document: string; year_reset: boolean; main: boolean };
type Fx = { date: string; currency: string; buy: string; sell: string; source: string; by: string | null };

const TABS = [
  { key: "general", label: "General" }, { key: "moneda", label: "Moneda e impuestos" }, { key: "series", label: "Series y correlativos" },
  { key: "politicas", label: "Políticas comerciales" }, { key: "operativos", label: "Parámetros operativos" }, { key: "canales", label: "Canales" },
];
const SECTION: Record<string, keyof Settings["sections"]> = { general: "general", moneda: "currency", politicas: "policies", operativos: "operations", canales: "channels" };
const LABELS: Record<string, string> = {
  legal_name: "Razón social", commercial_name: "Nombre comercial", logo_initials: "Iniciales del logo", timezone: "Zona horaria", email: "Correo de contacto", phone: "Teléfono",
  web: "Sitio web", address: "Dirección fiscal", legal_rep: "Representante legal", mincetur_registry: "Registro MINCETUR", lang: "Idioma", date_format: "Formato de fecha", main_sede_id: "Sede principal",
  alt_currency: "Moneda alterna", fx_rate: "Tipo de cambio USD", fx_source: "Fuente del tipo de cambio", tax_pct: "IGV (%)", rounding: "Redondeo", round_mode: "Modo de redondeo",
  quote_validity_days: "Vigencia de cotización (días)", hold_minutes: "Hold de cupos (minutos)", markup_pct: "Markup por defecto (%)", max_discount_pct: "Descuento máximo sin aprobación (%)",
  min_margin_pct: "Margen mínimo (%)", advance_pct: "Adelanto (%)", balance_days_before: "Saldo: días antes de la salida", cancel_text: "Política de cancelación", modify_text: "Política de modificaciones",
  noshow_text: "No show", refund_text: "Devoluciones", voucher_hours_before: "Vouchers: horas antes del servicio", assign_expiry_min: "Vencimiento de asignación (min)",
  checkin_tolerance_min: "Tolerancia de check-in (min)", modify_min_hours: "Modificación mínima (horas)", max_pax_per_guide: "Máximo de pax por guía", offline_sync_min: "Sincronización offline (min)",
  pay_reminder_days: "Recordatorio de pago (días)", trip_notice_hours: "Aviso de viaje (horas)", incident_escalate_min: "Escalar incidencia (min)",
};
const opts = (list: string[]) => list.map((v) => ({ value: v, label: v }));
const optsMap = (map: Record<string, string>) => Object.entries(map).map(([value, label]) => ({ value, label }));

/** Configuración del tenant (PROC-003, spec 09 §4.5): cada sección guarda solo lo modificado y audita por campo. */
export default async function SettingsPage({ params }: { params: Promise<{ tab: string }> }) {
  const { tab } = await params;
  const { data: s } = await api<{ data: Settings }>("/settings");

  return (
    <section>
      <PageHeader title="Configuración" subtitle={<>{s.tenant.code} · RUC {s.tenant.ruc} · Plan {s.tenant.plan}</>} />
      <RouteTabs base="/admin/configuracion" tabs={TABS} active={tab} />
      {tab === "series" ? <SequencesTab /> : SECTION[tab] ? <SectionTab tab={tab} s={s} /> : <Notice tone="warning">Sección inexistente.</Notice>}
    </section>
  );
}

function SectionTab({ tab, s }: { tab: string; s: Settings }) {
  const key = SECTION[tab];
  const { values, lock_version } = s.sections[key];
  const field = (name: string, el?: React.ReactNode) => el ?? <Input key={name} name={name} label={LABELS[name] ?? name} defaultValue={String(values[name] ?? "")} />;
  const select = (name: string, options: { value: string; label: string }[]) => <Select key={name} name={name} label={LABELS[name] ?? name} options={options} defaultValue={String(values[name] ?? "")} />;
  const num = (name: string, step = "1") => <Input key={name} name={name} label={LABELS[name] ?? name} type="number" step={step} defaultValue={String(values[name] ?? "")} />;

  return (
    <Card>
      <div className="p-4">
        <ActionForm path={`/settings/${key}`} method="PATCH" ifMatch={lock_version} submitLabel="Guardar cambios" className="space-y-4">
          {key === "general" && (
            <>
              <dl className="grid grid-cols-2 gap-2 rounded-md bg-slate-50 p-3 text-sm">
                <dt className="text-slate-500">RUC</dt><dd>{values.ruc}</dd><dt className="text-slate-500">País</dt><dd>{values.country}</dd>
                <dd className="col-span-2 text-xs text-slate-500">El RUC y el país requieren validación de la plataforma.</dd>
              </dl>
              <div className="grid gap-3 md:grid-cols-2">
                {["legal_name", "commercial_name", "logo_initials", "legal_rep", "email", "phone", "web", "mincetur_registry"].map((n) => field(n))}
                {select("timezone", opts(s.options.timezones))}
                {select("lang", optsMap(s.options.langs))}
                {select("date_format", opts(s.options.date_formats))}
                {select("main_sede_id", s.sedes.map((x) => ({ value: x.id, label: `${x.code} · ${x.name}` })))}
              </div>
              {field("address")}
            </>
          )}
          {key === "currency" && (
            <>
              {s.base_currency_locked
                ? <Notice>Moneda base: <strong>{values.base_currency}</strong>. No puede cambiarse porque ya existen reservas o pagos (CFG-BR-05).</Notice>
                : select("base_currency", opts(s.options.currencies))}
              <div className="grid gap-3 md:grid-cols-2">
                {select("alt_currency", opts(s.options.alt_currencies))}
                {num("fx_rate", "0.0001")}
                {select("fx_source", opts(s.options.fx_sources))}
                {num("tax_pct", "0.01")}
                {select("rounding", opts(s.options.roundings))}
                {select("round_mode", optsMap(s.options.round_modes))}
              </div>
              <label className="flex items-center gap-2 text-sm"><input type="hidden" name="price_with_tax" value="false" />
                <input type="checkbox" name="price_with_tax" value="true" defaultChecked={!!values.price_with_tax} /> Precios publicados con IGV incluido</label>
            </>
          )}
          {key === "policies" && (
            <>
              <div className="grid gap-3 md:grid-cols-3">{["quote_validity_days", "hold_minutes", "balance_days_before"].map((n) => num(n))}{["markup_pct", "max_discount_pct", "min_margin_pct", "advance_pct"].map((n) => num(n, "0.01"))}</div>
              {["cancel_text", "modify_text", "noshow_text", "refund_text"].map((n) => <TextArea key={n} name={n} label={LABELS[n]} defaultValue={String(values[n] ?? "")} rows={3} />)}
            </>
          )}
          {key === "operations" && <div className="grid gap-3 md:grid-cols-3">{Object.keys(values).map((n) => num(n))}</div>}
          {key === "channels" && (
            <ul className="divide-y divide-slate-100">
              {s.channels.map((c) => (
                <li key={c.key} className="flex flex-wrap items-center justify-between gap-3 py-3" data-channel={c.key}>
                  <div className="min-w-0">
                    <p className="font-medium text-slate-900">{c.label} {!c.editable && <Pill tone="amber">Desde plan {c.min_plan}</Pill>}</p>
                    <p className="text-xs text-slate-500">{c.description}{c.integration && ` · Integración ${c.integration}: ${c.integration_status ?? "sin configurar"}`}</p>
                  </div>
                  <label className="flex items-center gap-2 text-sm">
                    {c.editable && <input type="hidden" name={c.key} value="false" />}
                    <input type="checkbox" name={c.key} value="true" defaultChecked={!!values[c.key]} disabled={!c.editable} /> Activo
                  </label>
                </li>
              ))}
            </ul>
          )}
        </ActionForm>
      </div>
    </Card>
  );
}

async function SequencesTab() {
  const [{ data }, fx] = await Promise.all([
    api<{ data: { data: Sequence[]; can_add: { doc_type: string; label: string; prefix: string }[] } }>("/settings/sequences"),
    api<{ data: Fx[] }>("/settings/fx-rates"),
  ]);

  return (
    <div className="space-y-5">
      <Notice>Los correlativos solo avanzan: un número emitido no se reutiliza (CFG-BR-07).</Notice>
      <Table head={["Documento", "Serie", "Prefijo", "Siguiente número", "Próximo documento", ""]}>
        {data.data.map((q) => (
          <tr key={`${q.doc_type}-${q.series}`} data-sequence={q.doc_type}>
            <Td>{q.label}{q.year_reset && <span className="block text-xs text-slate-500">Reinicia cada año</span>}</Td>
            <Td>{q.series}</Td>
            <td colSpan={4} className="px-3 py-2 align-top">
              <ActionForm path="/settings/sequences" method="PATCH" inline submitLabel="Guardar" submitClass="rounded border border-slate-300 px-2 py-1 text-xs">
                <input type="hidden" name="items.0.doc_type" value={q.doc_type} />
                <input type="hidden" name="items.0.series" value={q.series} />
                <input name="items.0.prefix" defaultValue={q.prefix} aria-label={`Prefijo ${q.label}`} className="w-28 rounded border border-slate-300 px-2 py-1 font-mono text-xs" />
                <input name="items.0.next_number" type="number" min={1} defaultValue={q.next_number} aria-label={`Siguiente número ${q.label}`} className="w-24 rounded border border-slate-300 px-2 py-1 text-xs" />
                <span className="font-mono text-xs text-slate-600">{q.next_document}</span>
              </ActionForm>
            </td>
          </tr>
        ))}
      </Table>
      {data.can_add.length > 0 && (
        <Card title="Agregar serie">
          <div className="p-4">
            <ActionForm path="/settings/sequences" submitLabel="Agregar serie" className="grid gap-3 md:grid-cols-4">
              <Select name="doc_type" label="Documento" options={data.can_add.map((c) => ({ value: c.doc_type, label: `${c.label} (${c.prefix})` }))} required />
              <Input name="prefix" label="Prefijo" required />
              <Input name="next_number" label="Número inicial" type="number" min={1} defaultValue="1" />
            </ActionForm>
          </div>
        </Card>
      )}
      <Card title="Historial de tipo de cambio">
        <ul className="divide-y divide-slate-100 text-sm">
          {fx.data.map((r) => <li key={`${r.date}-${r.currency}`} className="flex justify-between px-4 py-2"><span>{date(r.date)} · {r.currency}</span><span>Compra {r.buy} · Venta {r.sell} · {r.source}</span></li>)}
        </ul>
      </Card>
    </div>
  );
}
