import { api } from "@/lib/api";
import { date } from "@/lib/crm";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Table, Td, btnGhostCls, inputCls } from "@/components/crm/ui";
import { ClaimsHeader } from "@/components/claims/ui";

type Settings = {
  response_days: number; alert_days: number; retention_years: number; default_owner_id: string | null; lock_version: number; can_edit: boolean;
  owners: { id: string; name: string }[]; holidays: { id: string; date: string; name: string; local: boolean }[];
  sequences: { sede: string; prefix: string; last: number }[];
};
const NEED = "Requiere permiso admin.settings.manage";

/** Parámetros del libro (§2, LR-BR-10): plazo en días hábiles, alerta, conservación, feriados y correlativos por sede. */
export default async function ClaimSettingsPage() {
  const s = (await api<{ data: Settings }>("/crm/claim-book/settings")).data;
  const ro = !s.can_edit;
  return (
    <>
      <ClaimsHeader active="configuracion" />
      {ro && <p className="mb-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700 ring-1 ring-slate-200" role="note">Solo lectura: editar los parámetros corresponde al administrador ({NEED}).</p>}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Parámetros">
          <ActionForm path="/crm/claim-book/settings" method="PUT" ifMatch={s.lock_version} submitLabel="Guardar" success="Configuración guardada" disabled={ro} disabledReason={NEED}>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Plazo de respuesta (días hábiles)</span>
              <input name="response_days" type="number" min={1} max={30} defaultValue={s.response_days} disabled={ro} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Alertar con (días hábiles de anticipación)</span>
              <input name="alert_days" type="number" min={0} max={10} defaultValue={s.alert_days} disabled={ro} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Conservación (años)</span>
              <input name="retention_years" type="number" min={2} max={20} defaultValue={s.retention_years} disabled={ro} className={inputCls} /></label>
            <label className="block text-sm"><span className="mb-1 block font-medium text-slate-700">Responsable por defecto</span>
              <select name="default_owner_id" defaultValue={s.default_owner_id ?? ""} disabled={ro} className={inputCls}><option value="">Gerencia (automático)</option>{s.owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</select></label>
            <p className="text-xs text-slate-500">El nuevo plazo rige solo para las hojas que se registren desde ahora.</p>
          </ActionForm>
        </Card>
        <Card title="Correlativos por sede">
          <Table head={["Sede", "Formato", "Último"]}>
            {s.sequences.map((q) => <tr key={q.sede}><Td>{q.sede}</Td><Td className="font-mono">{q.prefix}000001</Td><Td>{q.last}</Td></tr>)}
          </Table>
        </Card>
      </div>
      <Card title="Feriados (no cuentan como días hábiles)" className="mt-4">
        <ActionForm path="/crm/claim-book/settings" method="PUT" ifMatch={s.lock_version} submitLabel="Agregar feriado local" submitClass={btnGhostCls} success="Feriado agregado" disabled={ro} disabledReason={NEED} className="mb-4 flex flex-wrap items-end gap-2">
          <label className="text-sm"><span className="mb-1 block text-slate-700">Fecha</span><input name="add_holidays.0.date" type="date" required disabled={ro} className={inputCls} /></label>
          <label className="text-sm"><span className="mb-1 block text-slate-700">Nombre</span><input name="add_holidays.0.name" required maxLength={120} disabled={ro} className={inputCls} /></label>
        </ActionForm>
        <Table head={["Fecha", "Feriado", "Ámbito", ""]}>
          {s.holidays.map((h) => (
            <tr key={h.id}>
              <Td className="whitespace-nowrap">{date(h.date)}</Td><Td>{h.name}</Td><Td>{h.local ? "Local (agencia)" : "Nacional"}</Td>
              <Td>{h.local && !ro && <ActionForm path="/crm/claim-book/settings" method="PUT" ifMatch={s.lock_version} submitLabel="Quitar" submitClass="text-sm text-rose-700 hover:underline" success="Feriado quitado" inline>
                <input type="hidden" name="remove_holidays[]" value={h.id} /></ActionForm>}</Td>
            </tr>
          ))}
        </Table>
      </Card>
    </>
  );
}
