import { NextResponse, type NextRequest } from "next/server";
import { API_URL } from "@/lib/api";
import { sessionToken } from "@/lib/session";

/** Descarga de una exportación (PROC-061): pide la URL firmada (5 min, auditada) y transmite el archivo sin exponer el token. */
export async function GET(req: NextRequest, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const token = await sessionToken();
  const res = await fetch(`${API_URL}/reports/exports/${encodeURIComponent(id)}/download`, {
    redirect: "manual",
    cache: "no-store",
    headers: { Accept: "application/json", Authorization: `Bearer ${token}`, "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
  });
  const body = (await res.json().catch(() => null)) as { url?: string; message?: string } | null;
  if (!body?.url) return NextResponse.json(body ?? { message: "No se pudo descargar" }, { status: res.status === 302 ? 502 : res.status });

  const file = await fetch(body.url, { cache: "no-store" });
  if (!file.ok) return NextResponse.json({ message: "El enlace de descarga expiró o no es válido" }, { status: file.status });
  return new NextResponse(file.body, {
    headers: { "Content-Type": file.headers.get("Content-Type") ?? "application/octet-stream", "Content-Disposition": file.headers.get("Content-Disposition") ?? "attachment" },
  });
}
