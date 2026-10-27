import { RES_TONE } from "@/lib/reservations";

export function ResStatus({ value, label }: { value: string; label?: string }) {
  return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${RES_TONE[value] ?? RES_TONE.borrador}`}>{label ?? value}</span>;
}
