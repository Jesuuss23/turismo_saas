import { api } from "@/lib/api";
import type { OperatorBootstrap, OperatorPreview } from "@/lib/ops";
import { btnGhostCls, inputCls, PageHeader } from "@/components/crm/ui";
import { OperatorApp } from "@/components/mobile/OperatorApp";

/** App del operador (PROC-056). Guía/conductor: solo el teléfono con SU recurso. Internos: vista previa con "Ver como recurso" y panel del proceso. */
export default async function OperatorPage({ searchParams }: { searchParams: Promise<{ as?: string }> }) {
  const sp = await searchParams;
  const q = sp.as ? `?as=${encodeURIComponent(sp.as)}` : "";
  const { data, meta } = await api<{ data: OperatorBootstrap; meta: OperatorPreview }>(`/mobile/operator/bootstrap${q}`);
  const app = <OperatorApp key={data.resource.code} initial={data} asCode={sp.as} preview={meta.preview} />;
  if (!meta.preview) return <div className="py-2">{app}</div>;

  return (
    <>
      <PageHeader title="App del operador" subtitle="Vista previa interna de la app de guías y conductores" />
      <div className="grid gap-6 lg:grid-cols-[24rem_1fr]">
        {app}
        <aside className="space-y-4 text-sm text-slate-700">
          <form className="flex items-end gap-2 rounded-lg border border-slate-200 bg-white p-4">
            <label className="flex-1"><span className="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Ver como recurso</span>
              <select name="as" defaultValue={data.resource.code} className={inputCls} aria-label="Ver como recurso">
                {meta.resources.map((r) => <option key={r.code} value={r.code}>{r.code} · {r.name} ({r.type === "guide" ? "guía" : "conductor"})</option>)}
              </select></label>
            <button className={btnGhostCls}>Ver</button>
          </form>
          <section className="rounded-lg border border-slate-200 bg-white p-4">
            <h2 className="mb-2 font-semibold text-slate-900">PROC-056 · Ejecución del servicio en campo</h2>
            <ol className="list-inside list-decimal space-y-1">
              <li>El coordinador asigna el recurso; llega un push y la asignación aparece en <strong>Hoy</strong>.</li>
              <li>El recurso <strong>confirma</strong> la asignación (la orden puede pasar a Confirmada).</li>
              <li>En el punto de encuentro <strong>inicia</strong> el servicio: la orden pasa a En ejecución.</li>
              <li><strong>Check-in</strong> individual o grupal de los pasajeros.</li>
              <li>Registra <strong>evidencias</strong> (fotos) y, si ocurre algo, <strong>reporta una incidencia</strong>.</li>
              <li><strong>Finaliza</strong> el servicio: requiere al menos un check-in y una evidencia; los demás quedan como no-show.</li>
            </ol>
          </section>
          <section className="rounded-lg border border-slate-200 bg-white p-4">
            <h2 className="mb-2 font-semibold text-slate-900">Sin conexión</h2>
            <p>En <strong>Perfil</strong>, active &quot;Sin conexión&quot;: las acciones quedan en la bandeja de salida del dispositivo. Al volver a estar en línea se envían en lote, en el orden en que ocurrieron; el servidor descarta duplicados y resuelve conflictos (por ejemplo, una confirmación ya hecha desde la web o un check-in repetido) y la app muestra el resultado de cada acción.</p>
          </section>
        </aside>
      </div>
    </>
  );
}
