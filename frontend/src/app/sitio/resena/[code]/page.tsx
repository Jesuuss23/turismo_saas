import type { Metadata } from "next";
import { ApiError } from "@/lib/api";
import { prefs, siteApi } from "@/lib/site";
import { OwnReviewForm } from "@/components/experience/OwnReviewForm";
import { submitTokenReview } from "@/lib/own-review-actions";
import type { ReviewEligibility } from "@/lib/own-reviews";

export const metadata: Metadata = { title: "Califica tu viaje", robots: { index: false, follow: false } };

/** Enlace de la invitación (spec 18 RVW-09/10): formulario sin sesión, validado por el token de un solo uso. */
export default async function TokenReviewPage({ params, searchParams }: { params: Promise<{ code: string }>; searchParams: Promise<{ t?: string }> }) {
  const { code } = await params;
  const { t = "" } = await searchParams;
  const { locale } = await prefs();
  const en = locale === "en";
  let data: ReviewEligibility | null = null;
  let error: string | null = null;
  try {
    data = (await siteApi<{ data: ReviewEligibility }>(`reviews/requests/${encodeURIComponent(code)}`, { session: false, query: { t } })).data;
  } catch (e) {
    error = e instanceof ApiError ? (e.body?.message ?? "El enlace no es válido.") : "No se pudo conectar con el servidor.";
  }
  return (
    <div className="mx-auto max-w-2xl px-4 py-8">
      <h1 className="text-2xl font-semibold">{en ? "Rate your trip" : "Califica tu viaje"}</h1>
      {error || !data ? (
        <p role="alert" className="mt-4 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-800" data-token-error>{error}</p>
      ) : (
        <>
          <p className="mt-2 text-sm text-slate-600">
            {data.agency ?? ""} · {data.reservation.code}{data.passenger ? ` · ${data.passenger.name}` : ""}
          </p>
          <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-4">
            <OwnReviewForm action={submitTokenReview} items={data.items} subratings={data.subratings} langs={data.langs} hidden={{ request: data.request ?? code, t }} en={en} />
          </div>
        </>
      )}
    </div>
  );
}
