import { api } from "@/lib/api";
import { Card, Table, Td } from "@/components/crm/ui";
import { apiSection } from "@/components/billing/api";

type OpenApi = {
  info: { title: string; version: string; description: string }; servers: { url: string }[];
  paths: Record<string, Record<string, { summary: string; "x-scope"?: string; parameters?: { name: string; description?: string }[] }>>;
};

const VERIFY = `// Node.js: verificar Qhapaq-Signature (HMAC-SHA256 sobre "t.cuerpo_crudo")
import crypto from "node:crypto";

export function verify(secret, header, rawBody) {
  const parts = Object.groupBy(header.split(","), (p) => p.split("=")[0]);
  const t = Number(parts.t?.[0].split("=")[1]);
  if (!t || Math.abs(Date.now() / 1000 - t) > 300) return false; // máx. 5 minutos
  const expected = crypto.createHmac("sha256", secret).update(\`\${t}.\${rawBody}\`).digest("hex");
  // Durante la rotación llegan dos v1 (secreto nuevo y anterior): basta con que una coincida.
  return (parts.v1 ?? []).some((p) => {
    const sig = Buffer.from(p.slice(3));
    return sig.length === expected.length && crypto.timingSafeEqual(sig, Buffer.from(expected));
  });
}`;

/** Documentación de la API pública (spec 13 §2): endpoints, extracto OpenAPI 3.1 y guía de verificación de firmas. */
export default async function ApiDocsPage() {
  const { info, header } = await apiSection("docs");
  const doc = await api<OpenApi>("/docs/openapi.json");
  const rows = Object.entries(doc.paths).flatMap(([path, ops]) => Object.entries(ops).map(([method, op]) => ({ path, method, op })));

  return (
    <>
      {header}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={`${doc.info.title} · versión ${doc.info.version}`} className="min-w-0">
          <p className="mb-3 text-sm text-slate-700">{doc.info.description}</p>
          <p className="mb-3 text-sm">Base URL: <code className="break-all text-xs">{doc.servers[0]?.url}</code></p>
          <Table head={["Método", "Ruta", "Scope"]}>
            {rows.map((r) => (
              <tr key={`${r.method}-${r.path}`} data-endpoint={`${r.method.toUpperCase()} ${r.path}`}>
                <Td><span className="font-mono text-xs font-semibold">{r.method.toUpperCase()}</span></Td>
                <Td><code className="text-xs">{r.path}</code><span className="block text-xs text-slate-500">{r.op.summary}</span></Td>
                <Td><code className="text-xs">{r.op["x-scope"] ?? "—"}</code></Td>
              </tr>
            ))}
          </Table>
          <p className="mt-3 text-xs text-slate-500">Documento completo: <code>GET /api/v1/docs/openapi.json</code> (OpenAPI 3.1).</p>
        </Card>
        <div className="min-w-0 space-y-4">
          <Card title="Eventos de webhook">
            <ul className="grid gap-1 text-sm sm:grid-cols-2">{info.events.map((e) => <li key={e.key}><code className="text-xs">{e.key}</code> · {e.label}</li>)}</ul>
            <p className="mt-3 text-xs text-slate-500">Cada entrega lleva Qhapaq-Event-Id (idempotencia del receptor), Qhapaq-Event-Type, Qhapaq-Delivery-Id y Qhapaq-Signature. Responda 2xx en menos de 10 s; reintentamos con backoff y deshabilitamos el webhook tras 10 fallos o un 410.</p>
          </Card>
          <Card title="Verificar la firma">
            <pre className="overflow-x-auto rounded bg-slate-900 p-3 text-xs text-slate-100">{VERIFY}</pre>
          </Card>
          <Card title="Extracto OpenAPI · GET /reservations">
            <pre className="max-h-72 overflow-auto rounded bg-slate-50 p-2 text-xs">{JSON.stringify(doc.paths["/reservations"], null, 2)}</pre>
          </Card>
        </div>
      </div>
    </>
  );
}
