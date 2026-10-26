"use client";

import { btnCls } from "@/components/crm/ui";

export function PrintButton() {
  return <button type="button" className={btnCls} onClick={() => window.print()}>Imprimir o guardar PDF</button>;
}
