import { api } from "@/lib/api";
import { ActionForm } from "@/components/crm/ActionForm";
import { Card, Input, Notice } from "@/components/crm/ui";
import { CardForm } from "@/components/billing/CardForm";
import { Facts, SubscriptionHeader } from "@/components/billing/ui";
import { type Subscription } from "@/lib/billing";

type SetupIntent = { client_secret: string; mode: string; test_cards: { number: string; result: string }[] };

/** Método de pago (spec 13 §2): tarjeta tokenizada por el formulario embebido de la pasarela y datos de facturación (SUB-BR-09). */
export default async function PaymentMethodPage() {
  const [s, intent] = await Promise.all([
    api<{ data: Subscription }>("/billing/subscription").then((r) => r.data),
    api<{ data: SetupIntent }>("/billing/payment-methods/setup-intent", { method: "POST", body: "{}" }).then((r) => r.data),
  ]);
  const pm = s.payment_method;
  const b = s.subscription.billing;

  return (
    <>
      <SubscriptionHeader active="pago" />
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Tarjeta">
          {pm ? (
            <div className="mb-4 rounded-lg border border-slate-200 p-3 text-sm" data-payment-method={pm.last4}>
              <p className="font-medium text-slate-900">{pm.brand.toUpperCase()} •••• {pm.last4}</p>
              <p className="text-slate-600">{pm.holder} · vence {pm.exp}</p>
            </div>
          ) : <div className="mb-4"><Notice tone="warning">No tiene una tarjeta registrada: la renovación no podrá cobrarse.</Notice></div>}
          <p className="mb-3 text-xs text-slate-500">
            Formulario seguro de la pasarela ({intent.mode}). Qhapaq Cloud nunca almacena el número ni el CVV. Tarjetas de prueba:{" "}
            {intent.test_cards.map((c) => `${c.number} (${c.result.toLowerCase()})`).join(" · ")}.
          </p>
          <CardForm holder={pm?.holder ?? ""} />
        </Card>

        <Card title="Datos de facturación">
          <Facts rows={[["RUC", b.ruc], ["Razón social", b.name], ["Dirección", b.address], ["Correo", b.email]]} />
          <details className="mt-3">
            <summary className="cursor-pointer text-sm text-sky-800">Editar datos de facturación</summary>
            <ActionForm path="/billing/subscription" method="PATCH" ifMatch={s.subscription.lock_version} submitLabel="Guardar" className="mt-3 grid gap-3 sm:grid-cols-2">
              <Input name="billing.ruc" label="RUC" defaultValue={b.ruc} required pattern="(10|15|17|20)\d{9}" />
              <Input name="billing.email" label="Correo de facturación" type="email" defaultValue={b.email} required />
              <div className="sm:col-span-2"><Input name="billing.name" label="Razón social" defaultValue={b.name} required /></div>
              <div className="sm:col-span-2"><Input name="billing.address" label="Dirección fiscal" defaultValue={b.address} required /></div>
              <Input name="billing.ubigeo" label="Ubigeo" defaultValue={b.ubigeo ?? ""} />
            </ActionForm>
            <p className="mt-2 text-xs text-slate-500">Los cambios aplican a las próximas facturas; las emitidas no se modifican.</p>
          </details>
        </Card>
      </div>
    </>
  );
}
