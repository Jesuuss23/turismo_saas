import { Drawer, Input, Notice } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";
import { pct, type Target } from "@/lib/marketing";

/** Ajuste de una meta en borrador o vigente: motivo obligatorio (MET-BR-04) y aviso de bloqueo de reducción (MET-BR-05). */
export function AdjustDrawer({ t, close, admin, lockPct }: { t: Target; close: string; admin: boolean; lockPct: number }) {
  return (
    <Drawer title={`Ajustar ${t.code}`} close={close}>
      {t.status === "vigente" && t.days.elapsed_pct > lockPct && !admin && (
        <Notice tone="warning">Ya transcurrió el {pct(t.days.elapsed_pct)} del período: reducir la meta requiere al Tenant Admin (MET-BR-05).</Notice>
      )}
      <ActionForm path={`/crm/sales-targets/${t.code}/adjust`} ifMatch={t.lock_version} submitLabel="Guardar ajuste" redirectTo={`/crm/metas/${t.code}`}>
        <Input name="goal_sales_pen" label="Meta de ventas S/" type="number" min={1} step="0.01" defaultValue={t.goals.sales} required />
        <Input name="goal_bookings" label="Meta de reservas" type="number" min={0} defaultValue={t.goals.bookings ?? ""} />
        <Input name="goal_margin_pen" label="Meta de margen S/" type="number" min={0} step="0.01" defaultValue={t.goals.margin ?? ""} />
        <Input name="goal_conversion_pct" label="Meta de conversión %" type="number" min={0} max={100} step="0.1" defaultValue={t.goals.conversion ?? ""} />
        {t.scope_type === "asesor" && <Input name="bonus_amount" label="Bono S/ al 100 %" type="number" min={0} step="0.01" defaultValue={t.goals.bonus ?? ""} />}
        <Input name="reason" label="Motivo (obligatorio)" required />
      </ActionForm>
    </Drawer>
  );
}

