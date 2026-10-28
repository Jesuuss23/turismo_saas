/** Línea simple en SVG (historial de TC): escala entre el mínimo y el máximo de la serie para que se vea la variación diaria. */
export function LineChart({ points, label }: { points: { x: string; y: number; alert?: boolean }[]; label: string }) {
  if (points.length < 2) return <p className="text-sm text-slate-500">Sin historial suficiente.</p>;
  const W = 600, H = 160, P = 8;
  const ys = points.map((p) => p.y);
  const min = Math.min(...ys), max = Math.max(...ys);
  const span = max - min || 1;
  const x = (i: number) => P + (i * (W - 2 * P)) / (points.length - 1);
  const y = (v: number) => H - P - ((v - min) / span) * (H - 2 * P);
  const d = points.map((p, i) => `${i ? "L" : "M"}${x(i).toFixed(1)},${y(p.y).toFixed(1)}`).join(" ");
  return (
    <figure>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={label} className="h-40 w-full" preserveAspectRatio="none">
        <path d={d} fill="none" stroke="currentColor" strokeWidth={2} vectorEffect="non-scaling-stroke" className="text-sky-700" />
        {points.map((p, i) => p.alert && <circle key={p.x} cx={x(i)} cy={y(p.y)} r={4} className="fill-rose-600"><title>{`${p.x}: ${p.y}`}</title></circle>)}
      </svg>
      <figcaption className="mt-1 flex justify-between text-[11px] text-slate-500">
        <span>{points[0].x}</span><span>mín. {min.toFixed(4)} · máx. {max.toFixed(4)}</span><span>{points[points.length - 1].x}</span>
      </figcaption>
    </figure>
  );
}
