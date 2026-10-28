/** Campo de un parámetro de regla (CAT-RL-03): número con su rango o sí/no para los booleanos (spec 18: `moderation`). */
export function RuleParamInput({ name, value, def }: { name: string; value: number | boolean | undefined; def: { type: string; minimum?: number; maximum?: number } }) {
  const cls = "ml-1 rounded border border-slate-300 px-1 py-0.5";
  return (
    <label className="text-xs">{name}
      {def.type === "boolean"
        ? <select name={`params.${name}`} defaultValue={value ? "true" : "false"} className={cls}><option value="true">sí</option><option value="false">no</option></select>
        : <input name={`params.${name}`} type="number" step="any" min={def.minimum} max={def.maximum} defaultValue={value === undefined ? undefined : Number(value)} className={`${cls} w-20`} />}
    </label>
  );
}
