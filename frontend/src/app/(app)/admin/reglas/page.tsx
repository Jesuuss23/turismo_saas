import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, PageHeader, Select, TextArea } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { RULE_GROUPS, RULE_SCOPES, type AdminRule } from "@/lib/booking";

const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50";

/**
 * Centro de reglas de reserva (spec 16 §2): reglas del motor agrupadas, activar/desactivar por tenant, parámetros por defecto,
 * canales donde aplican y reglas propias del tenant (se guardan, pero el motor solo evalúa las que tienen handler).
 */
export default async function BookingRulesPage() {
  const { data: rules } = await api<{ data: AdminRule[] }>("/admin/booking-rules");
  const groups = RULE_GROUPS.filter((g) => rules.some((r) => r.group === g));

  return (
    <section>
      <PageHeader title="Reglas de reserva" subtitle="Reglas del motor de reservas online. Apagar una regla no borra la configuración guardada en los productos." />
      <div className="space-y-5">
        {groups.map((g) => (
          <Card key={g} title={g}>
            <ul className="divide-y divide-slate-100">
              {rules.filter((r) => r.group === g).map((r) => <RuleItem key={r.key} r={r} />)}
            </ul>
          </Card>
        ))}

        <Card title="Agregar regla">
          <ActionForm path="/admin/booking-rules" submitLabel="Agregar regla" success="Regla creada" className="grid gap-3 p-4 sm:grid-cols-2">
            <Input name="rule_key" label="Clave (R-…)" required maxLength={40} placeholder="R-MI-REGLA" />
            <Select name="group_name" label="Grupo" options={RULE_GROUPS.map((x) => ({ value: x, label: x }))} required />
            <Input name="name" label="Nombre" required maxLength={120} />
            <Input name="params" label="Parámetros (nombre=valor, separados por coma)" placeholder="minPax=2, dias=7" />
            <div className="sm:col-span-2"><TextArea name="description" label="Descripción" required maxLength={2000} /></div>
            <input type="hidden" name="scope[]" value="Sitio web" />
            <p className="text-xs text-slate-500 sm:col-span-2">Las reglas propias quedan registradas y se pueden activar o sobrescribir por producto, pero el motor no las evalúa hasta que exista su implementación.</p>
          </ActionForm>
        </Card>
      </div>
    </section>
  );
}

function RuleItem({ r }: { r: AdminRule }) {
  const names = Object.keys(r.schema?.properties ?? {});
  const path = `/admin/booking-rules/${r.key}`;
  return (
    <li className="grid gap-2 px-4 py-3 lg:grid-cols-[minmax(0,1fr)_auto]" data-admin-rule={r.key}>
      <div className="min-w-0">
        <p className="flex flex-wrap items-center gap-2">
          <span className="font-medium">{r.name}</span>
          <span className="font-mono text-[11px] text-slate-500">{r.key}</span>
          {r.enabled ? <Pill tone="green">Activa</Pill> : <Pill>Inactiva</Pill>}
          {!r.is_system && <Pill tone="violet">{r.evaluated ? "propia" : "propia · no evaluada"}</Pill>}
          {r.product_override ? <Pill tone="sky">Por producto{r.overrides ? ` · ${r.overrides} excepción(es)` : ""}</Pill> : <Pill>Solo tenant</Pill>}
        </p>
        <p className="mt-1 text-sm text-slate-600">{r.description}</p>
        <div className="mt-2 flex flex-wrap items-end gap-3">
          {names.length > 0 && (
            <ActionForm path={path} method="PATCH" submitLabel="Guardar parámetros" submitClass={small} success="Parámetros guardados" className="flex flex-wrap items-end gap-2">
              {names.map((n) => (
                <label key={n} className="text-xs">{n}
                  <input name={`params.${n}`} type="number" step="any" min={r.schema.properties![n].minimum} max={r.schema.properties![n].maximum} defaultValue={r.params[n]} className="ml-1 w-20 rounded border border-slate-300 px-1 py-0.5" />
                </label>
              ))}
            </ActionForm>
          )}
          <ActionForm path={path} method="PATCH" submitLabel="Guardar canales" submitClass={small} success="Canales guardados" className="flex flex-wrap items-center gap-2">
            {RULE_SCOPES.map((s) => (
              <label key={s} className="flex items-center gap-1 text-xs"><input type="checkbox" name="scope[]" value={s} defaultChecked={r.scope.includes(s)} />{s}</label>
            ))}
          </ActionForm>
        </div>
      </div>
      <ActionForm path={path} method="PATCH" submitLabel={r.enabled ? "Desactivar" : "Activar"} submitClass={small} success={r.enabled ? "Regla desactivada" : "Regla activada"} inline>
        <input type="hidden" name="enabled" value={r.enabled ? "false" : "true"} />
      </ActionForm>
    </li>
  );
}
