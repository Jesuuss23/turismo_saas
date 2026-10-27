import Link from "@/components/Link";
import { api } from "@/lib/api";
import { Notice } from "@/components/crm/ui";
import { API_FEATURE_LABEL, type PublicApi } from "@/lib/billing";
import { ApiHeader } from "./ui";

/** Carga la configuración de la API pública y dibuja el encabezado con las pestañas que el plan habilita. */
export async function apiSection(active: string): Promise<{ info: PublicApi; header: React.ReactNode }> {
  const info = (await api<{ data: PublicApi }>("/integrations/public-api")).data;
  return { info, header: <ApiHeader active={active} features={info.features} /> };
}

export function NotInPlan({ feature }: { feature: string }) {
  return (
    <Notice tone="warning">
      Su plan no incluye {API_FEATURE_LABEL[feature] ?? feature}. <Link href="/admin/suscripcion" className="font-medium underline">Mejore su plan</Link> para habilitarlo.
    </Notice>
  );
}
