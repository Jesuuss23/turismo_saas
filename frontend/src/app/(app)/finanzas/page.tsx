import { redirect } from "next/navigation";

/** `/finanzas` es alias de Cuentas por cobrar (spec 07 §2). */
export default function FinanceIndex() {
  redirect("/finanzas/cobros");
}
