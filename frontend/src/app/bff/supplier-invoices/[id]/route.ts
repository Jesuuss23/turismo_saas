import { NextResponse, type NextRequest } from "next/server";
import { API_URL } from "@/lib/api";
import { sessionToken } from "@/lib/session";

/**
 * Proxy del comprobante del proveedor. `?scope=fin` lo pide Finanzas (finance.payables); sin scope, el propio proveedor
 * (solo facturas de su cuenta). El backend valida token, permiso y pertenencia en cada llamada.
 */
export async function GET(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^[0-9a-f-]{36}$/i.test(id)) return new NextResponse(null, { status: 404 });
  const path = req.nextUrl.searchParams.get("scope") === "fin" ? `/fin/supplier-invoices/${id}/file` : `/supplier/invoices/${id}/file`;
  const token = await sessionToken();
  const res = await fetch(`${API_URL}${path}`, {
    headers: { Accept: "*/*", Authorization: `Bearer ${token}`, "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    cache: "no-store",
  });
  return new NextResponse(res.body, {
    status: res.status,
    headers: { "Content-Type": res.headers.get("Content-Type") ?? "application/octet-stream", "Content-Disposition": res.headers.get("Content-Disposition") ?? "inline" },
  });
}
