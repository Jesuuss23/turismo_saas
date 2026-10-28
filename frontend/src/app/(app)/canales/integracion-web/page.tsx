import { headers } from "next/headers";
import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import Link from "@/components/Link";
import { Card, Input, Notice, PageHeader, Select, Table, Td, Tabs } from "@/components/crm/ui";
import { Pill } from "@/components/admin/ui";
import { CopyBlock } from "@/components/experience/CopyBlock";
import { WidgetConfigurator } from "@/components/experience/WidgetConfigurator";
import { widgetSnippet } from "@/lib/widget-snippet";
import { dateTime } from "@/lib/crm";
import { money } from "@/lib/finance";
import type { CurrencyList, WidgetSettings } from "@/lib/experience";

const small = "rounded-md border border-slate-300 bg-white px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50";
const TABS = [
  { key: "configurador", label: "Configurador y vista previa" },
  { key: "metodos", label: "Métodos de integración" },
  { key: "dominios", label: "Dominios y seguridad" },
  { key: "eventos", label: "Eventos y webhooks" },
];
const METHOD_HELP: Record<string, string> = {
  webcomponent: "a) Web Component: una etiqueta y un script. Recomendado para cualquier CMS que permita HTML; hereda el ancho del contenedor.",
  iframe: "b) iframe: aislamiento total del sitio anfitrión; el alto se ajusta con el evento qhapaq:resize.",
  wordpress: "c) Plugin WordPress: instale «Qhapaq Booking», pegue la clave pública en Ajustes y use los shortcodes en páginas o bloques.",
  headless: "d) API headless: su desarrollador arma la interfaz y llama a la API pública con la clave; el precio siempre lo calcula el servidor.",
};
const CMS_GUIDE: Record<string, string> = {
  WordPress: "Plugins → Añadir nuevo → «Qhapaq Booking» → Ajustes → pegue la clave pública. Use [qhapaq_booking] o [qhapaq_catalog] en un bloque «Shortcode».",
  Wix: "Agregar → Insertar código → Insertar HTML. Pegue el código del Web Component o del iframe y ajuste el alto del elemento.",
  Squarespace: "Editar página → Agregar bloque → Código. Pegue el Web Component (planes Business o superiores).",
  Shopify: "Tienda online → Temas → Personalizar → Sección «HTML personalizado». Pegue el Web Component.",
  Webflow: "Agregue un elemento «Embed» y pegue el Web Component; publique el sitio para que cargue el script.",
  "HTML propio": "Pegue el script una vez en el <head> y la etiqueta donde quiera mostrar el widget.",
  Otro: "Cualquier CMS que acepte HTML admite el Web Component o el iframe; si no, use la API headless.",
};
const HANDOFF_TONE: Record<string, "sky" | "amber" | "green" | "slate"> = { pendiente: "sky", en_carrito: "amber", convertido: "green", expirado: "slate" };

type Search = { tab?: string };

/**
 * Integración web (spec 17 §2): configurador con vista previa en vivo del widget real, métodos a–d con guía por CMS, dominios con
 * verificación DNS TXT (CORS y CSP resultantes), clave pública con regeneración (`admin.api.manage`) y eventos/webhooks del widget.
 */
export default async function WebIntegrationPage({ searchParams }: { searchParams: Promise<Search> }) {
  const sp = await searchParams;
  const tab = TABS.some((t) => t.key === sp.tab) ? sp.tab! : "configurador";
  const [{ data: cfg, meta }, fx] = await Promise.all([
    api<{ data: WidgetSettings; meta: { can_rotate: boolean } }>("/channels/widget"),
    api<{ data: CurrencyList }>("/finance/currencies").then((r) => r.data).catch(() => null),
  ]);
  const h = await headers();
  const origin = `${h.get("x-forwarded-proto") ?? "http"}://${h.get("x-forwarded-host") ?? h.get("host") ?? "localhost:3000"}`;
  const currencies = fx ? fx.items.filter((c) => c.active).map((c) => c.code) : [cfg.defaults.currency];
  const categories = [...new Set(cfg.products.map((p) => p.category).filter(Boolean))].sort();
  const verified = cfg.domains.filter((d) => d.status === "verificado");

  return (
    <section>
      <PageHeader title="Integración web" subtitle="Inserte el motor de reservas en el sitio de la agencia (cualquier CMS): mismo calendario, reglas y precios que el sitio propio." />
      <Tabs base="/canales/integracion-web" tabs={TABS} active={tab} />

      {tab === "configurador" && (
        <div className="space-y-4">
          <WidgetConfigurator cfg={cfg} origin={origin} currencies={currencies} categories={categories} canEdit />
          <Card title="Clave pública">
            <p className="font-mono text-sm" data-public-key>{cfg.public_key}</p>
            {cfg.public_key_prev && <p className="mt-1 text-xs text-slate-500">La clave anterior (…{cfg.public_key_prev.slice(-4)}) sigue válida hasta {dateTime(cfg.prev_expires_at)} (WG-02).</p>}
            <p className="mt-1 text-xs text-slate-500">La clave identifica a la agencia; no es secreta. Solo responde a los dominios verificados.</p>
            <ActionForm path="/channels/widget/rotate-key" idempotent submitLabel="Regenerar clave" submitClass={small} success="Clave regenerada: la anterior sigue válida 24 h" className="mt-3 flex flex-wrap items-end gap-2"
              confirm="¿Regenerar la clave pública? Deberá actualizar el código en su sitio en las próximas 24 h." disabled={!meta.can_rotate} disabledReason="Requiere permiso admin.api.manage">
              <div className="min-w-64 flex-1"><Input name="reason" label="Motivo" required minLength={5} maxLength={200} disabled={!meta.can_rotate} /></div>
            </ActionForm>
          </Card>
        </div>
      )}

      {tab === "metodos" && (
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
            {Object.entries(cfg.methods).map(([k, label]) => (
              <Card key={k} title={label}>
                <p className="mb-2 text-sm text-slate-600">{METHOD_HELP[k]}</p>
                <CopyBlock text={widgetSnippet({ ...cfg.defaults, method: k, product: cfg.defaults.product ?? "PRD-013" }, cfg.public_key, origin)} />
              </Card>
            ))}
          </div>
          <Card title="Guía por CMS">
            <dl className="grid gap-3 text-sm md:grid-cols-2">
              {cfg.cms.map((c) => <div key={c}><dt className="font-medium">{c}</dt><dd className="text-slate-600">{CMS_GUIDE[c]}</dd></div>)}
            </dl>
          </Card>
        </div>
      )}

      {tab === "dominios" && (
        <div className="space-y-4">
          <Notice>WG-01: el widget y la API pública solo responden con CORS a los dominios verificados por un registro DNS TXT. El dominio pendiente se reintenta cada hora.</Notice>
          <Table head={["Dominio", "CMS", "Estado", "Registro TXT", ""]} empty="Sin dominios registrados">
            {cfg.domains.map((d) => (
              <tr key={d.id} data-domain={d.domain}>
                <Td className="font-medium">{d.domain}</Td>
                <Td>{d.cms ?? "—"}</Td>
                <Td><Pill tone={d.status === "verificado" ? "green" : "amber"}>{d.status}</Pill><span className="block text-xs text-slate-500">{d.verified_at ? `verificado ${dateTime(d.verified_at)}` : d.last_check_at ? `revisado ${dateTime(d.last_check_at)}` : ""}</span></Td>
                <Td className="text-xs"><span className="font-mono">{d.txt_record.host}</span><span className="block break-all font-mono text-slate-500">{d.txt_record.value}</span></Td>
                <Td>
                  <div className="flex flex-wrap gap-1">
                    {d.status !== "verificado" && <ActionForm path={`/channels/widget/domains/${d.id}/verify`} submitLabel="Verificar" submitClass={small} success="Verificación ejecutada" inline />}
                    <ActionForm path={`/channels/widget/domains/${d.id}`} method="DELETE" submitLabel="Eliminar" submitClass={small} success="Dominio eliminado" confirm={`¿Eliminar ${d.domain}? El widget dejará de responder en ese sitio.`} inline />
                  </div>
                </Td>
              </tr>
            ))}
          </Table>
          <div className="grid grid-cols-1 gap-4 [&>*]:min-w-0 lg:grid-cols-2">
            <Card title="Agregar dominio">
              <ActionForm path="/channels/widget/domains" submitLabel="Agregar dominio" success="Dominio agregado: cree el registro TXT y verifique" className="space-y-3">
                <Input name="domain" label="Dominio (sin https:// ni rutas)" required maxLength={253} placeholder="www.miagencia.com" pattern="^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$" />
                <Select name="cms" label="CMS" options={cfg.cms.map((c) => ({ value: c, label: c }))} placeholder="—" />
              </ActionForm>
            </Card>
            <Card title="CORS y CSP resultantes">
              <CopyBlock text={verified.length
                ? `${verified.map((d) => `Access-Control-Allow-Origin: https://${d.domain}`).join("\n")}\nContent-Security-Policy: frame-ancestors 'self' ${verified.map((d) => `https://${d.domain}`).join(" ")}`
                : "Sin dominios verificados: la API pública no envía cabeceras CORS y el iframe solo se muestra en el propio sitio."} />
            </Card>
          </div>
        </div>
      )}

      {tab === "eventos" && (
        <div className="space-y-4">
          <Card title="Webhooks relevantes">
            <Table head={["Evento", "Suscripciones"]}>
              {cfg.webhook_events.map((e) => {
                const subs = cfg.webhooks.filter((w) => w.events.includes(e));
                return (
                  <tr key={e} data-webhook-event={e}>
                    <Td className="font-mono text-xs">{e}</Td>
                    <Td className="text-xs">{subs.length ? subs.map((w) => <span key={w.id} className="block break-all">{w.url} · {w.status}</span>) : <span className="text-slate-500">Sin suscripción · <Link href="/admin/api/webhooks" className="underline">configurar en API y webhooks</Link></span>}</Td>
                  </tr>
                );
              })}
            </Table>
          </Card>
          <Card title="Eventos del widget hacia la página anfitriona">
            <ul className="mb-3 space-y-1 text-sm">{Object.entries(cfg.browser_events).map(([k, v]) => <li key={k}><span className="font-mono text-xs">{k}</span> · {v}</li>)}</ul>
            <CopyBlock text={`window.addEventListener("message", function (e) {\n  if (!e.data || typeof e.data.type !== "string" || e.data.type.indexOf("qhapaq:") !== 0) return;\n  // Ej.: Google Analytics\n  if (e.data.type === "qhapaq:checkout") gtag("event", "begin_checkout", { transaction_id: e.data.code, value: e.data.total, currency: e.data.currency });\n});`} />
          </Card>
          <Card title="Últimos traspasos al checkout">
            <Table head={["Código", "Producto", "Total", "Origen", "Estado", "Creado"]} empty="Aún no hay traspasos desde el widget">
              {cfg.handoffs.map((t) => (
                <tr key={t.code} data-handoff={t.code}>
                  <Td className="font-mono text-xs">{t.code}</Td>
                  <Td>{t.product ? `${t.product.ref} · ${t.product.name}` : "—"}</Td>
                  <Td>{t.total ? money(t.total, t.currency) : "—"}</Td>
                  <Td className="text-xs">{t.origin_domain ?? "—"}</Td>
                  <Td><Pill tone={HANDOFF_TONE[t.status] ?? "slate"}>{t.status.replace("_", " ")}</Pill></Td>
                  <Td className="text-xs">{dateTime(t.created_at)}</Td>
                </tr>
              ))}
            </Table>
          </Card>
        </div>
      )}
      <p className="mt-6 text-xs text-slate-500">La vista previa carga el widget público real con los datos y reglas de la agencia; los traspasos de prueba vencen a las 24 h.</p>
    </section>
  );
}
