const API_BASE = "/api";

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    headers: { Accept: "application/json", "Content-Type": "application/json", ...(init?.headers ?? {}) },
    ...init,
  });
  const body = (await res.json().catch(() => ({}))) as T & { error?: string };
  if (!res.ok) {
    throw new Error(body.error || `请求失败（${res.status}）`);
  }
  return body;
}

export type Health = {
  micromamba: boolean;
  python_env: boolean;
  ax_importable: boolean;
  mnl_importable: boolean;
  ready: boolean;
};

export type ApiProject = {
  id: string;
  task_uuid: string;
  name: string;
  engine: string;
  objective: string;
  batches: number;
  experiments: number;
  best: string;
  updated: string;
  status: "运行中" | "待录入" | "已完成";
  latest_uuid: string;
  demo_mode?: boolean;
};

export type OptimizeResponse = {
  ok: boolean;
  uuid?: string;
  task_uuid?: string;
  status?: string;
  result?: Record<string, unknown>;
  experiment_log?: Record<string, unknown>[];
  error?: string;
};

export async function fetchHealth(): Promise<Health> {
  return request<Health>("/health");
}

export async function fetchProjects(): Promise<ApiProject[]> {
  const data = await request<{ projects: ApiProject[] }>("/projects");
  return data.projects ?? [];
}

export async function runOptimize(payload: Record<string, unknown>): Promise<OptimizeResponse> {
  return request<OptimizeResponse>("/optimize", { method: "POST", body: JSON.stringify(payload) });
}

export async function fetchRun(uuid: string) {
  return request<{
    ok: boolean;
    status: string;
    uuid?: string;
    task_uuid?: string;
    engine?: string;
    result?: Record<string, unknown>;
    config?: Record<string, unknown>;
    experiment_log?: Record<string, unknown>[];
    started_at?: string | null;
    finished_at?: string | null;
    error?: string | null;
    result_error?: string;
  }>(`/runs/${uuid}`);
}
