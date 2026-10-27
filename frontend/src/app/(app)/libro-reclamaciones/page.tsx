import { api } from "@/lib/api";
import { ClaimForm } from "@/components/claims/ClaimForm";
import { MyClaims } from "@/components/claims/MyClaims";
import { PageHeader } from "@/components/crm/ui";
import type { ClaimFormData, PortalClaim } from "@/lib/claims";

type Form = ClaimFormData & {
  mode: "customer" | "passenger" | "preview"; reservation: string | null; mine: PortalClaim[];
  prefill: { name: string; doc_type: string; doc_number: string; email: string; phone: string | null; address: string; good: string; reservation?: string };
};

/** Libro de reclamaciones virtual (spec 12 §2): portal del cliente o del pasajero con «Mis hojas»; un interno con crm.claims.manage lo ve como vista previa. */
export default async function ClaimBookPortalPage() {
  const f = (await api<{ data: Form }>("/portal/claim-book/form")).data;
  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader title="Libro de reclamaciones" subtitle={f.mode === "preview" ? "Vista previa interna del formulario que ven el cliente y el pasajero." : "Hoja de reclamación virtual conforme al Código de Protección y Defensa del Consumidor."} />
      <ClaimForm form={f} prefill={f.prefill} mode={f.mode} path="/portal/claim-book" />
      {f.mode !== "preview" && (
        <section className="mt-8">
          <h2 className="mb-3 text-lg font-semibold text-slate-900">Mis hojas</h2>
          <MyClaims rows={f.mine} />
        </section>
      )}
    </div>
  );
}
