const TONES: Record<string, string> = {
  sky: "from-sky-700 via-sky-500 to-amber-200",
  emerald: "from-emerald-800 via-emerald-500 to-sky-200",
  amber: "from-amber-700 via-orange-400 to-rose-200",
};

/** Imagen ilustrativa (montañas SVG) mientras el CMS no tenga fotos: liviana y sin dependencias externas. */
export function Picture({ label, tone = "sky", className = "" }: { label: string; tone?: string; className?: string }) {
  return (
    <div role="img" aria-label={label} className={`relative overflow-hidden bg-gradient-to-br ${TONES[tone] ?? TONES.sky} ${className}`}>
      <svg viewBox="0 0 400 160" preserveAspectRatio="none" className="absolute inset-x-0 bottom-0 h-3/4 w-full" aria-hidden>
        <path d="M0 160 L70 70 L120 115 L190 30 L260 105 L310 60 L400 140 L400 160 Z" fill="rgba(11,31,58,0.55)" />
        <path d="M0 160 L90 105 L150 135 L230 80 L300 125 L360 100 L400 120 L400 160 Z" fill="rgba(11,31,58,0.8)" />
        <path d="M190 30 L176 48 L190 44 L204 50 Z" fill="white" opacity="0.85" />
      </svg>
      <span className="absolute bottom-2 left-3 text-xs font-medium text-white drop-shadow">{label}</span>
    </div>
  );
}
