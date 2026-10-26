import { addDays, today, type CatalogMeta } from "@/lib/catalog";
import { Card, Input, Select } from "@/components/crm/ui";
import { ActionForm } from "@/components/crm/ActionForm";

export function DepartureForm({ path, meta, productSelect }: { path: string; meta: CatalogMeta; productSelect?: boolean }) {
  return (
    <Card title="Nueva salida">
      <ActionForm path={path} idempotent submitLabel="Programar salida" success="Salida programada" className="grid gap-2 sm:grid-cols-4">
        {productSelect && <Select label="Producto" name="product_id" options={meta.products.filter((x) => !["INACTIVE", "ARCHIVED"].includes(x.status)).map((x) => ({ value: x.id, label: `${x.ref} · ${x.name}` }))} />}
        <Input label="Fecha" name="departure_date" type="date" defaultValue={addDays(today(), 7)} required />
        <Input label="Hora" name="departure_time" type="time" defaultValue="06:00" required />
        <Input label="Capacidad" name="capacity" type="number" min={1} max={60} defaultValue={16} required />
        <Input label="Punto de encuentro" name="meeting_point" defaultValue="Plaza de Armas de Cusco" required />
        <Select label="Guía" name="guide_id" placeholder="(sin asignar)" options={meta.guides.map((g) => ({ value: g.id, label: `${g.name}${g.on_vacation ? " · vacaciones" : ""}` }))} />
        <Select label="Vehículo" name="vehicle_id" placeholder="(sin asignar)" options={meta.vehicles.map((v) => ({ value: v.id, label: `${v.plate} (${v.seats})${v.in_maintenance ? " · mantenimiento" : ""}` }))} />
      </ActionForm>
    </Card>
  );
}
