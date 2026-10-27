import { NextResponse, type NextRequest } from "next/server";
import { API_URL } from "@/lib/api";
import { sessionToken } from "@/lib/session";

/** Descargas del libro de reclamaciones, tesorería y facturas de la plataforma (spec 13); el backend valida alcance y audita. */
const GET_PATHS = /^\/(crm\/claim-book|portal\/claim-book)\/[A-Z0-9-]{3,30}\/certificate$|^\/treasury\/movements\/[A-Z0-9-]{3,30}\/receipt$|^\/billing\/invoices\/[A-Z0-9-]{3,30}\/pdf$|^\/platform\/invoices\/[\w-]{3,40}\/pdf$/i;
const POST_PATHS = /^\/crm\/claim-book\/exports$/;

async function forward(req: NextRequest, method: "GET" | "POST", path: string | null, allowed: RegExp) {
  if (!path || !allowed.test(path)) return new NextResponse(null, { status: 404 });
  const res = await fetch(`${API_URL}${path}`, {
    method,
    headers: { Accept: "*/*", "Content-Type": "application/json", Authorization: `Bearer ${await sessionToken()}`, "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    body: method === "POST" ? await req.text() : undefined,
    cache: "no-store",
  });
  return new NextResponse(res.body, {
    status: res.status,
    headers: { "Content-Type": res.headers.get("Content-Type") ?? "application/octet-stream", "Content-Disposition": res.headers.get("Content-Disposition") ?? "inline" },
  });
}

export async function GET(req: NextRequest) {
  return forward(req, "GET", req.nextUrl.searchParams.get("path"), GET_PATHS);
}

export async function POST(req: NextRequest) {
  return forward(req, "POST", req.nextUrl.searchParams.get("path"), POST_PATHS);
}
