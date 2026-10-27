import { NextResponse, type NextRequest } from "next/server";
import { API_URL } from "@/lib/api";
import { sessionToken } from "@/lib/session";

/** Proxy de la evidencia (imagen/PDF): el backend valida token y `operations.view` en cada llamada. */
export async function GET(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  if (!/^[0-9a-f-]{36}$/i.test(id)) return new NextResponse(null, { status: 404 });
  const token = await sessionToken();
  const res = await fetch(`${API_URL}/ops/evidences/${id}/file`, {
    headers: { Accept: "*/*", Authorization: `Bearer ${token}`, "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    cache: "no-store",
  });
  return new NextResponse(res.body, {
    status: res.status,
    headers: { "Content-Type": res.headers.get("Content-Type") ?? "application/octet-stream", "Content-Disposition": res.headers.get("Content-Disposition") ?? "inline" },
  });
}
