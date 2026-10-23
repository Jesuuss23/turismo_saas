import { NextResponse, type NextRequest } from "next/server";
import { API_URL } from "@/lib/api";
import { sessionToken } from "@/lib/session";

/** Proxy de descarga: reenvía la exportación al backend con el token de la cookie httpOnly. */
export async function POST(req: NextRequest) {
  const token = await sessionToken();
  const res = await fetch(`${API_URL}/exports`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}`, "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    body: await req.text(),
  });
  return new NextResponse(res.body, {
    status: res.status,
    headers: { "Content-Type": res.headers.get("Content-Type") ?? "application/json", "Content-Disposition": res.headers.get("Content-Disposition") ?? "" },
  });
}
