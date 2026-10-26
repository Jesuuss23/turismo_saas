import Link from "@/components/Link";
import { api } from "@/lib/api";
import { fetchScoped } from "@/lib/crm-server";
import { toDocument, type Quote } from "@/lib/quotes";
import { OutOfScope } from "@/components/crm/ui";
import { QuoteDocument } from "@/components/quotes/QuoteDocument";
import { PrintButton } from "@/components/quotes/PrintButton";

/** Vista de impresión (reemplaza al PDF generado por job): el navegador imprime o guarda como PDF. */
export default async function PrintQuotePage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const res = await fetchScoped<{ data: Quote }>(`/quotes/${code}`);
  if (!res) return <OutOfScope back="/cotizaciones" />;
  const q = res.data;
  const { data: me } = await api<{ data: { tenant: { name: string } } }>("/me");
  return (
    <div className="mx-auto max-w-3xl">
      <div className="mb-4 flex items-center justify-between print:hidden">
        <Link href={`/cotizaciones/${q.code}`} className="text-sm text-sky-800 hover:underline">← Volver</Link>
        <PrintButton />
      </div>
      {q.version && <QuoteDocument doc={toDocument(q, q.version)} agency={me.tenant.name} />}
    </div>
  );
}
