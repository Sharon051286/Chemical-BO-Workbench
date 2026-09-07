import type { Encoding, ParamRow } from "@/lib/edbo-data";

export const SESSION_KEY = "edbo-formal-session";
export const UNNAMED = "未命名课题";

type ParamSpec = { name?: string; encoding?: string; values?: unknown };

export function paramsFromConfig(parameters: unknown): ParamRow[] {
  if (!Array.isArray(parameters)) return [];
  return (parameters as ParamSpec[]).map((p, i) => ({
    id: `p${i}`,
    name: String(p.name ?? ""),
    encoding: (p.encoding === "ohe" || p.encoding === "resolve" ? p.encoding : "numeric") as Encoding,
    values: Array.isArray(p.values) ? p.values.map(String).join(", ") : String(p.values ?? ""),
  }));
}

export function normalizeResult(result: Record<string, unknown> | null | undefined) {
  if (!result) return null;
  if (!Array.isArray(result.predicted_next) && Array.isArray(result.recommended_experiments)) {
    return { ...result, predicted_next: result.recommended_experiments };
  }
  return result;
}

export function persistFormalSession(taskUuid: string | null, projectName: string) {
  if (typeof window === "undefined") return;
  if (!taskUuid) {
    window.sessionStorage.removeItem(SESSION_KEY);
    return;
  }
  window.sessionStorage.setItem(SESSION_KEY, JSON.stringify({ taskUuid, projectName }));
}

export function readFormalSession(): { taskUuid: string; projectName: string } | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.sessionStorage.getItem(SESSION_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as { taskUuid?: string; projectName?: string };
    if (!parsed.taskUuid) return null;
    return { taskUuid: parsed.taskUuid, projectName: parsed.projectName || UNNAMED };
  } catch {
    return null;
  }
}
