import Link from "@/components/Link";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Drawer, Input, Notice, PageHeader, Table, Td, btnCls, btnGhostCls } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { amount } from "@/components/collab/approvals";

type Rule = {
  id: string; code: string; type: string; type_label: string; level: number; permission: string; approvers: string[]; min_amount_pen: string; sla_hours: number;
  forbid_self_approval: boolean; is_active: boolean; is_origin_locked: boolean; lock_version: number;
};
type Meta = { can_edit: boolean; types: Record<string, string>; origin_permissions: Record<string, string>; permissions: string[] };

const selectCls = "w-full rounded-md border border-slate-300 px-3 py-2 text-sm";

/** Matriz de reglas de aprobación (spec 10 §3.1): consulta con approvals.view, edición con admin.settings.manage. */
export default async function ApprovalRulesPage({ searchParams }: { searchParams: Promise<{ r?: string; nueva?: string }> }) {
  const sp = await searchParams;
  const { data, meta } = await api<{ data: Rule[]; meta: Meta }>("/approval-rules");
  const rule = sp.r ? data.find((r) => r.code === sp.r) : undefined;
  const back = "/aprobaciones/reglas";

  return (
    <section>
      <PageHeader title="Reglas de aprobación" subtitle="Tipo → permiso, nivel, umbral de monto (en soles) y SLA. Por nivel se usa la regla de mayor umbral que aplique."
        actions={meta.can_edit ? (
          <>
            <Link href={`${back}?nueva=1`} className={btnCls}>Nueva regla</Link>
            <ActionForm path="/approval-rules/reset" submitLabel="Restaurar por defecto" submitClass={btnGhostCls} success="Matriz restaurada" inline
              confirm="¿Restaurar la matriz por defecto? Se reemplazan todas las reglas; las solicitudes en curso conservan su ruta." />
          </>
        ) : undefined} />
      <nav className="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm" aria-label="Bandejas">
        <Link href="/aprobaciones" className="px-3 py-2 text-slate-600 hover:text-slate-900">Bandeja</Link>
        <span className="-mb-px border-b-2 border-sky-700 px-3 py-2 font-medium text-sky-800">Reglas de aprobación</span>
      </nav>
      {!meta.can_edit && <Notice>Consulta de la matriz. Solo el Tenant Admin (admin.settings.manage) puede editarla.</Notice>}

      <Table head={["Regla", "Tipo", "Nivel", "Permiso", "Aprobadores actuales", "Monto desde", "SLA", "Autoaprobación", "Estado"]}>
        {data.map((r) => (
          <tr key={r.id} data-rule={r.code}>
            <Td>{meta.can_edit ? <Link href={`${back}?r=${r.code}`} scroll={false} className="font-mono text-sky-800 hover:underline">{r.code}</Link> : <span className="font-mono">{r.code}</span>}</Td>
            <Td>{r.type_label}</Td>
            <Td>{r.level}</Td>
            <Td><span className="font-mono text-xs">{r.permission}</span>{r.is_origin_locked && <span className="block text-xs text-slate-500">Fijado por el origen</span>}</Td>
            <Td className="max-w-xs">{r.approvers.length ? r.approvers.join(", ") : <span className="text-rose-700">Sin usuarios activos</span>}</Td>
            <Td className="whitespace-nowrap">{Number(r.min_amount_pen) > 0 ? amount(r.min_amount_pen) : "S/ 0"}</Td>
            <Td>{r.sla_hours} h</Td>
            <Td>{r.forbid_self_approval ? "Prohibida" : "Permitida"}</Td>
            <Td><Pill tone={r.is_active ? "green" : "slate"}>{r.is_active ? "Activa" : "Inactiva"}</Pill></Td>
          </tr>
        ))}
      </Table>

      {meta.can_edit && sp.nueva && (
        <Drawer title="Nueva regla" close={back}>
          <ActionForm path="/approval-rules" submitLabel="Crear regla" redirectTo={back}>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Tipo</span>
              <select name="request_type" required className={selectCls}>{Object.entries(meta.types).map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Nivel</span>
              <select name="level" defaultValue="2" className={selectCls}><option value="1">1</option><option value="2">2</option></select></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Permiso del aprobador</span>
              <select name="permission" required className={selectCls}>{meta.permissions.map((p) => <option key={p} value={p}>{p}</option>)}</select></label>
            <Input name="min_amount_pen" label="Monto desde (S/)" type="number" min={0} step="0.01" defaultValue={0} required />
            <Input name="sla_hours" label="SLA (horas)" type="number" min={1} max={720} defaultValue={24} required />
            <label className="flex items-center gap-2 text-sm"><input type="hidden" name="forbid_self_approval" value="false" /><input type="checkbox" name="forbid_self_approval" value="true" defaultChecked /> Prohibir autoaprobación</label>
            <p className="text-xs text-slate-500">El nivel 1 de cotización, anulación, reversión, CxP, comisión y tarifa usa el permiso de su módulo de origen (APR-BR-02).</p>
          </ActionForm>
        </Drawer>
      )}

      {meta.can_edit && rule && (
        <Drawer title={`${rule.code} · ${rule.type_label} nivel ${rule.level}`} close={back}>
          <div className="space-y-4">
            <ActionForm path={`/approval-rules/${rule.code}`} method="PATCH" ifMatch={rule.lock_version} submitLabel="Guardar regla" success="Regla actualizada">
              <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Permiso del aprobador</span>
                {rule.is_origin_locked
                  ? <input value={rule.permission} readOnly className={`${selectCls} bg-slate-50 font-mono`} aria-describedby="locked" />
                  : <select name="permission" defaultValue={rule.permission} className={selectCls}>{meta.permissions.map((p) => <option key={p} value={p}>{p}</option>)}</select>}
              </label>
              {rule.is_origin_locked && <p id="locked" className="text-xs text-slate-500">Fijado por el módulo de origen (APR-BR-02).</p>}
              <Input name="min_amount_pen" label="Monto desde (S/)" type="number" min={0} step="0.01" defaultValue={rule.min_amount_pen} required />
              <Input name="sla_hours" label="SLA (horas)" type="number" min={1} max={720} defaultValue={rule.sla_hours} required />
              <label className="flex items-center gap-2 text-sm"><input type="hidden" name="forbid_self_approval" value="false" /><input type="checkbox" name="forbid_self_approval" value="true" defaultChecked={rule.forbid_self_approval} /> Prohibir autoaprobación</label>
              <label className="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="false" /><input type="checkbox" name="is_active" value="true" defaultChecked={rule.is_active} /> Activa</label>
              <p className="text-xs text-slate-500">Las solicitudes en curso conservan el SLA con el que nacieron; el umbral aplica a las nuevas.</p>
            </ActionForm>
            {rule.level === 2 && (
              <ActionForm path={`/approval-rules/${rule.code}`} method="DELETE" ifMatch={rule.lock_version} submitLabel="Eliminar regla" redirectTo={back}
                submitClass="rounded-md bg-rose-700 px-3 py-2 text-sm font-medium text-white hover:bg-rose-800" confirm={`¿Eliminar ${rule.code}?`} />
            )}
          </div>
        </Drawer>
      )}
    </section>
  );
}
