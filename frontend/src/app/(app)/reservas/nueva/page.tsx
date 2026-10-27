import { api } from "@/lib/api";
import type { ResMeta } from "@/lib/reservations";
import { Notice, PageHeader } from "@/components/crm/ui";
import Link from "@/components/Link";
import { DirectForm, type DirectProduct } from "@/components/reservations/DirectForm";

/** Reserva directa sin cotización (spec 04 §4.7): Ventas, Gerencia y Admin. */
export default async function NewReservationPage() {
  const [{ data: meta }, { data: products }] = await Promise.all([
    api<{ data: ResMeta }>("/reservations/meta"),
    api<{ data: DirectProduct[] }>("/reservations/products").catch(() => ({ data: [] as DirectProduct[] })),
  ]);
  return (
    <>
      <PageHeader title="Nueva reserva directa" subtitle="Venta de mostrador o B2B sin cotización previa"
        actions={<Link href="/reservas" className="text-sm text-sky-800 hover:underline">← Reservas</Link>} />
      {!meta.can.create
        ? <Notice tone="warning">Su rol no puede crear reservas (requiere reservations.create).</Notice>
        : <DirectForm meta={meta} products={products} />}
    </>
  );
}
