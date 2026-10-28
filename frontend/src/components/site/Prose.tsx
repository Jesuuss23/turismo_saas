import type { ReactNode } from "react";

/** Cuerpo de CMS en texto plano: «#»/«##» encabezados, «-» viñetas y el resto párrafos. No interpreta HTML. */
export function Prose({ body }: { body: string | null }) {
  const out: ReactNode[] = [];
  let list: string[] = [];
  const flush = () => {
    if (list.length) out.push(<ul key={out.length} className="list-disc space-y-1 pl-5">{list.map((l, j) => <li key={j}>{l}</li>)}</ul>);
    list = [];
  };
  for (const raw of (body ?? "").replace(/\r/g, "").split("\n")) {
    const line = raw.trim();
    if (/^[-•*] /.test(line)) {
      list.push(line.slice(2));
      continue;
    }
    flush();
    if (!line) continue;
    const h = /^(#{1,3}) (.+)$/.exec(line);
    if (h) out.push(h[1].length === 1 ? <h2 key={out.length} className="pt-2 text-xl font-semibold text-slate-900">{h[2]}</h2> : <h2 key={out.length} className="pt-2 text-lg font-semibold text-slate-900">{h[2]}</h2>);
    else out.push(<p key={out.length} className="leading-relaxed">{line}</p>);
  }
  flush();
  return <div className="space-y-3 text-slate-700">{out}</div>;
}
