import { NextResponse, type NextRequest } from "next/server";
import { API_URL } from "@/lib/api";
import { sessionToken } from "@/lib/session";

/** Proxy del documento de viaje: el backend valida alcance y registra `ver_documento` en cada llamada. */
export async function GET(req: NextRequest, { params }: { params: Promise<{ code: string; pax: string; type: string }> }) {
  const { code, pax, type } = await params;
  if (!/^[A-Z0-9-]{3,30}$/i.test(code) || !/^[0-9a-f-]{36}$/i.test(pax) || !/^[a-z_]{2,40}$/.test(type)) return new NextResponse(null, { status: 404 });
  const token = await sessionToken();
  const res = await fetch(`${API_URL}/reservations/${code}/travel-docs/${pax}/${type}/file`, {
    headers: { Accept: "*/*", Authorization: `Bearer ${token}`, "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    cache: "no-store",
  });
  return new NextResponse(res.body, {
    status: res.status,
    headers: { "Content-Type": res.headers.get("Content-Type") ?? "application/octet-stream", "Content-Disposition": res.headers.get("Content-Disposition") ?? "inline" },
  });
}
