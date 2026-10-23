import { NextResponse, type NextRequest } from "next/server";

const API_URL = process.env.API_URL ?? "http://localhost:8000/api/v1";
const PUBLIC = ["/login", "/mfa", "/403", "/sitio"];
/** Proxys BFF de datos (descargas): el backend valida token y permiso en cada llamada. */
const BFF = ["/bff/"];

/**
 * SEC-BR-04: cada navegación se decide en el backend con la misma regla del menú
 * (GET /me/access/check). El frontend solo refleja; el backend vuelve a validar cada endpoint.
 */
export async function middleware(req: NextRequest) {
  const { pathname, search } = req.nextUrl;
  if (PUBLIC.some((p) => pathname === p || pathname.startsWith(p + "/"))) return NextResponse.next();
  if (BFF.some((p) => pathname.startsWith(p))) return NextResponse.next();

  const token = req.cookies.get("tz_token")?.value;
  if (!token) return toLogin(req);
  if (req.cookies.get("tz_mfa")) return NextResponse.redirect(new URL("/mfa", req.url));

  const requestId = "c-" + crypto.randomUUID().replace(/-/g, "").slice(0, 12);
  const path = pathname === "/" ? "dashboard" : pathname;
  const res = await fetch(`${API_URL}/me/access/check?path=${encodeURIComponent(path)}`, {
    headers: { Accept: "application/json", Authorization: `Bearer ${token}`, "X-Request-Id": requestId },
    cache: "no-store",
  }).catch(() => null);

  if (!res || res.status === 401) return toLogin(req, true);
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    return forbidden(req, { path, reason: body.message ?? "Acceso restringido", required: body.required_permissions ?? [], home: "", canRequest: false });
  }

  const decision = await res.json();
  if (pathname === "/") return NextResponse.redirect(new URL("/" + decision.home, req.url));
  if (decision.allowed) {
    const headers = new Headers(req.headers);
    headers.set("x-request-id", requestId);
    headers.set("x-pathname", pathname + search);
    return NextResponse.next({ request: { headers } });
  }
  if (decision.redirect) return NextResponse.redirect(new URL("/" + decision.redirect, req.url));

  return forbidden(req, {
    path: decision.path,
    reason: decision.reason,
    required: decision.required_permissions,
    home: decision.home,
    canRequest: decision.can_request_access,
  });
}

function toLogin(req: NextRequest, clear = false) {
  const url = new URL("/login", req.url);
  if (req.nextUrl.pathname !== "/") url.searchParams.set("next", req.nextUrl.pathname);
  const res = NextResponse.redirect(url);
  if (clear) res.cookies.delete("tz_token");
  return res;
}

/** Pantalla 403 en la misma URL solicitada (rewrite). */
function forbidden(req: NextRequest, d: { path: string; reason: string; required: string[]; home: string; canRequest: boolean }) {
  const url = new URL("/403", req.url);
  url.searchParams.set("path", d.path);
  url.searchParams.set("reason", d.reason);
  if (d.required.length) url.searchParams.set("required", d.required.join(","));
  if (d.home) url.searchParams.set("home", d.home);
  if (d.canRequest) url.searchParams.set("request", "1");
  return NextResponse.rewrite(url);
}

export const config = {
  matcher: ["/((?!_next/|favicon.ico|.*\\.(?:png|svg|jpg|ico|css|js)$).*)"],
};
