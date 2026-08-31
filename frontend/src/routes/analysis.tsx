import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect, useMemo, useState } from "react";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Scatter,
  ScatterChart,
  Tooltip as RTooltip,
  XAxis,
  YAxis,
} from "recharts";

import { Badge } from "@/components/ui/badge";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { fetchProjects, fetchRun } from "@/lib/api";
import { useAppMode } from "@/lib/app-mode";
import { convergence as demoConv, importance as demoImportance, paretoDominated as demoParetoRest, paretoFront as demoParetoFront, slice as demoSlice } from "@/lib/edbo-data";
import { buildDemoProjectResult, DEMO_PROJECTS, demoRunConfig } from "@/lib/demo-showcase";

type Search = { uuid?: string };

export const Route = createFileRoute("/analysis")({
  validateSearch: (search: Record<string, unknown>): Search => ({
    uuid: typeof search.uuid === "string" && search.uuid !== "" ? search.uuid : undefined,
  }),
  head: () => ({
    meta: [
      { title: "分析视图 · EDBO Web" },
      {
        name: "description",
        content: "查看收敛曲线、参数重要性、参数切片与多目标 Pareto 前沿等分析图表。",
      },
      { property: "og:title", content: "分析视图 · EDBO Web" },
      { property: "og:description", content: "收敛曲线、参数重要性、切片与 Pareto 前沿。" },
    ],
  }),
  component: Analysis,
});

const axis = {
  stroke: "var(--color-muted-foreground)",
  fontSize: 11,
  tickLine: false,
  axisLine: false,
};

const tooltipStyle = {
  contentStyle: {
    background: "var(--color-popover)",
    border: "1px solid var(--color-border)",
    borderRadius: "8px",
    fontSize: "12px",
  },
};

const legendProps = {
  iconSize: 10,
  wrapperStyle: {
    fontSize: 12,
    color: "var(--color-muted-foreground)",
    paddingTop: 4,
  },
};

function asRows(v: unknown): Record<string, unknown>[] {
  return Array.isArray(v) ? (v as Record<string, unknown>[]) : [];
}

function num(v: unknown): number | null {
  const n = typeof v === "number" ? v : Number(v);
  return Number.isFinite(n) ? n : null;
}

function cell(v: unknown): string {
  if (v == null || v === "") return "—";
  if (typeof v === "number") return Number.isInteger(v) ? String(v) : String(Number(v.toFixed(3)));
  return String(v);
}

type ParamSpec = { name?: string; encoding?: string; values?: unknown[] };

function formatParamValues(param: ParamSpec): string {
  const values = Array.isArray(param.values) ? param.values : [];
  if ((param.encoding ?? "numeric") === "numeric" && values.length >= 2) {
    return `${values[0]} ~ ${values[values.length - 1]}（连续）`;
  }
  return values.map(String).join(", ");
}

function predColLabel(key: string, target: string): string {
  if (key === `predicted_${target}` || key === "predicted_yield") return `预测${target}`;
  if (key === "variance") return "方差";
  return key.replaceAll("_", " ");
}

function pickBestRow(rows: Record<string, unknown>[], target: string): Record<string, unknown> | null {
  let best: Record<string, unknown> | null = null;
  let score = -Infinity;
  for (const row of rows) {
    const y = num(row[target]);
    if (y != null && y > score) {
      score = y;
      best = row;
    }
  }
  return best;
}

function Panel({
  title,
  hint,
  children,
  empty,
  emptyText,
}: {
  title: string;
  hint: string;
  children: React.ReactNode;
  empty?: boolean;
  emptyText?: string;
}) {
  return (
    <section className="panel p-5">
      <h2 className="text-base font-semibold">{title}</h2>
      <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
      <div className="mt-4 h-72">
        {empty ? (
          <div className="flex h-full items-center justify-center px-6 text-center text-sm text-muted-foreground">
            {emptyText || "暂无数据"}
          </div>
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            {children as React.ReactElement}
          </ResponsiveContainer>
        )}
      </div>
    </section>
  );
}

function Analysis() {
  const { demoMode } = useAppMode();
  const { uuid: searchUuid } = Route.useSearch();
  const [uuid, setUuid] = useState<string | undefined>(searchUuid);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<Record<string, unknown> | null>(null);
  const [log, setLog] = useState<Record<string, unknown>[]>([]);
  const [runInfo, setRunInfo] = useState<{
    uuid?: string;
    status?: string;
    engine?: string;
    started_at?: string | null;
    finished_at?: string | null;
    config?: Record<string, unknown>;
    error?: string | null;
  }>({});
  const [meta, setMeta] = useState<{ task?: string; status?: string; title?: string }>({});

  useEffect(() => {
    let cancelled = false;
    async function load() {
      if (demoMode) {
        const demo = DEMO_PROJECTS.find((p) => p.latest_uuid === searchUuid) ?? DEMO_PROJECTS[0];
        if (!cancelled) {
          setUuid(demo.latest_uuid);
          setMeta({ task: demo.task_uuid, status: demo.status, title: demo.name });
          setResult(buildDemoProjectResult(demo.latest_uuid));
          setLog([]);
          setRunInfo({
            uuid: demo.latest_uuid,
            status: "completed",
            engine: demo.engine,
            started_at: "2026-08-31 09:00:00",
            finished_at: "2026-08-31 09:00:08",
            config: demoRunConfig(),
            error: null,
          });
          setError(null);
          setLoading(false);
        }
        return;
      }
      setLoading(true);
      setError(null);
      try {
        let id = searchUuid;
        if (!id || id.startsWith("demo-")) {
          const projects = await fetchProjects();
          id = projects[0]?.latest_uuid;
        }
        if (!id) {
          if (!cancelled) {
            setUuid(undefined);
            setResult(null);
            setLog([]);
            setRunInfo({});
            setMeta({});
          }
          return;
        }
        const run = await fetchRun(id);
        if (cancelled) return;
        setUuid(id);
        setMeta({ task: run.task_uuid, status: run.status });
        setLog(Array.isArray(run.experiment_log) ? run.experiment_log : []);
        setRunInfo({
          uuid: run.uuid ?? id,
          status: run.status,
          engine: run.engine,
          started_at: run.started_at,
          finished_at: run.finished_at,
          config: run.config,
          error: run.error ?? run.result_error ?? null,
        });
        if (run.result) {
          setResult(run.result);
          setError(null);
        } else {
          setResult(null);
          setError(run.status === "completed" && run.result_error ? run.result_error : null);
        }
      } catch (e) {
        if (!cancelled) setError(e instanceof Error ? e.message : "加载失败");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, [searchUuid, demoMode]);

  const charts = useMemo(() => {
    const target =
      typeof result?.target === "string"
        ? result.target
        : uuid === "demo-mnl"
          ? "cycles"
          : "yield";
    const paramNames = Array.isArray(result?.parameter_names) ? (result.parameter_names as string[]) : [];
    const experiments = asRows(result?.experiments);
    const records = experiments.length > 0 ? experiments : log;
    const names =
      paramNames.length > 0
        ? paramNames
        : records[0]
          ? Object.keys(records[0]).filter(
              (k) => !["batch", "variance", "predicted_yield", "predicted_cycles", "predicted_conversion"].includes(k) && k !== target,
            )
          : [];
    const bestObj =
      result?.best && typeof result.best === "object" ? (result.best as Record<string, unknown>) : null;
    const best = bestObj ?? pickBestRow(records, target);
    const paretoRows = asRows(result?.pareto_front);
    const objectives = Array.isArray(result?.objectives)
      ? (result.objectives as { name?: string; minimize?: boolean }[])
      : [];
    const empty = {
      conv: [] as { batch: string; best: number; mean: number }[],
      importance: [] as { name: string; value: number }[],
      slice: [] as { temp: number; mean: number }[],
      sliceName: "",
      paretoFront: [] as { yield: number; cost: number }[],
      paretoRest: [] as { yield: number; cost: number }[],
      target,
      n: records.length,
      names,
      records,
      best,
      paretoRows,
      objectives,
      predicted: asRows(result?.predicted_next ?? result?.recommended_experiments),
      domainSize: num(result?.domain_size),
      evals: num(result?.total_evaluations) ?? records.length,
    };

    if (demoMode) {
      return {
        ...empty,
        conv: demoConv,
        importance: demoImportance,
        slice: demoSlice,
        sliceName: names[0] || "反应温度",
        paretoFront: demoParetoFront,
        paretoRest: demoParetoRest,
      };
    }
    if (!result) {
      return empty;
    }
    const convRaw = asRows(result.convergence);
    const conv = convRaw.map((c, i) => ({
      batch: `S${c.step ?? i + 1}`,
      best: num(c[`best_${target}`] ?? c.best_yield ?? c.best) ?? 0,
      mean: num(c[`mean_${target}`] ?? c.mean_yield ?? c.mean) ?? 0,
    }));

    const importanceRows = paramNames
      .map((name) => {
        const groups = new Map<string, number[]>();
        for (const e of records) {
          const y = num(e[target]);
          if (y == null) continue;
          const k = String(e[name] ?? "");
          const arr = groups.get(k) ?? [];
          arr.push(y);
          groups.set(k, arr);
        }
        const means = [...groups.values()].map((arr) => arr.reduce((a, b) => a + b, 0) / arr.length);
        const range = means.length ? Math.max(...means) - Math.min(...means) : 0;
        return { name, value: Number(range.toFixed(3)) };
      })
      .sort((a, b) => b.value - a.value);

    const totalImp = importanceRows.reduce((s, x) => s + x.value, 0) || 1;
    const importancePct = importanceRows.map((x) => ({
      name: x.name,
      value: Number(((x.value / totalImp) * 100).toFixed(1)),
    }));

    const numericParam =
      paramNames.find((name) => records.some((e) => num(e[name]) != null)) ?? paramNames[0] ?? "";
    const slicePts = records
      .map((e) => ({ temp: num(e[numericParam]), mean: num(e[target]) }))
      .filter((p): p is { temp: number; mean: number } => p.temp != null && p.mean != null)
      .sort((a, b) => a.temp - b.temp)
      .map((p) => ({ ...p, upper: p.mean, lower: p.mean }));

    const objs = Array.isArray(result.objectives)
      ? (result.objectives as { name?: string }[])
      : [];
    const xKey = objs[1]?.name ?? "cost";
    const frontRows = asRows(result.pareto_front);
    const paretoPts = (frontRows.length ? frontRows : []).map((r) => ({
      yield: num(r[target] ?? r.yield) ?? 0,
      cost: num(r[xKey] ?? r.cost) ?? 0,
    }));
    const frontKeys = new Set(paretoPts.map((p) => `${p.yield}|${p.cost}`));
    const paretoRest = records
      .map((r) => ({
        yield: num(r[target]) ?? 0,
        cost: num(r[xKey]) ?? 0,
      }))
      .filter((p) => !frontKeys.has(`${p.yield}|${p.cost}`));

    return {
      ...empty,
      conv,
      importance: importancePct,
      slice: slicePts,
      sliceName: numericParam,
      paretoFront: paretoPts,
      paretoRest,
      n: records.length,
    };
  }, [result, log, demoMode, uuid]);

  const detail = useMemo(() => {
    const cfg = runInfo.config ?? {};
    const parameters = Array.isArray(cfg.parameters) ? (cfg.parameters as ParamSpec[]) : [];
    const prior = Array.isArray(cfg.prior_results)
      ? cfg.prior_results.length
      : Array.isArray(cfg.experiment_log)
        ? (cfg.experiment_log as unknown[]).length
        : 0;
    const predKeys =
      charts.predicted[0] != null
        ? [
            ...charts.names.filter((n) => n in charts.predicted[0]),
            ...Object.keys(charts.predicted[0]).filter((k) => !charts.names.includes(k)),
          ]
        : charts.names;
    return {
      parameters,
      prior,
      batchSize: cfg.batch_size ?? result?.batch_size ?? "—",
      iterations: cfg.iterations ?? result?.iterations ?? "—",
      target: (typeof cfg.target === "string" ? cfg.target : charts.target) ?? "—",
      predKeys,
    };
  }, [runInfo, result, charts.predicted, charts.names, charts.target]);

  const runStatus = (runInfo.status ?? "").toLowerCase();
  const hasRun = Boolean(runInfo.uuid);
  const hasResult = Boolean(result);
  const isFailed = runStatus === "failed";
  const isPending = runStatus === "pending" || runStatus === "processing";
  const statusLabel =
    ({ completed: "已完成", failed: "失败", pending: "排队中", processing: "运行中" } as Record<string, string>)[
      runStatus
    ] ?? (runInfo.status || "暂无");

  const vacancy = (kind: "metric" | "best" | "records" | "chart") => {
    if (isFailed) return kind === "metric" ? "暂无（运行失败）" : "这次运行失败，没有可填充的数据。";
    if (isPending) return kind === "metric" ? "待生成" : "优化还在进行，完成后会填入。";
    if (!hasRun) return kind === "metric" ? "暂无" : "还没有正式运行，这里先空着。";
    if (!hasResult) return kind === "metric" ? "暂无" : "这次运行没有结果文件，无法填充。";
    if (kind === "best") return "已有运行，但还没有带目标值的实验，因此没有最优组合。";
    if (kind === "records") return "还没有写入实验记录。若这是首轮推荐，请先回填实测。";
    return "还没有足够的评估数据来画这张图。";
  };

  const headerHint = demoMode
    ? `演示样例 · ${meta.title ?? "Suzuki 偶联条件优化"}：最优组合、全部实验记录与图表`
    : loading
      ? "正在读取后端运行结果…"
      : !hasRun
        ? "还没有可分析的真实运行。请先在工作台用正式模式跑一轮优化。"
        : `运行 ${uuid?.slice(0, 8) ?? "暂无"} · 课题 ${meta.task?.slice(0, 8) ?? "暂无"}${
            isFailed ? " · 本次运行失败，只保留配置，结果为空" : isPending ? " · 优化仍在进行，结果位先留空" : hasResult ? "" : " · 没有可读的结果文件"
          }`;

  const metricBest =
    hasResult && charts.best?.[charts.target] != null
      ? `${cell(charts.best[charts.target])}${charts.target === "yield" || charts.target === "conversion" ? "%" : ""}`
      : vacancy("metric");
  const metricEvals = hasResult ? String(charts.evals) : vacancy("metric");
  const metricDomain = hasResult && charts.domainSize != null ? String(charts.domainSize) : vacancy("metric");

  const resultIntro = isFailed
    ? "这次运行没有成功写出结果。下面指标按空值填充，不是「已完成」。"
    : isPending
      ? "优化尚未完成。最优值、评估次数、域规模和下一批推荐会在完成后填入。"
      : hasResult && !charts.best && charts.evals === 0
        ? "本次已结束，但还没有实测评估（常见于首轮空间填充）。最优产率记为暂无，评估次数为 0。"
        : hasResult
          ? "当前运行已完成，以下为该次优化的结果摘要与模型推荐。"
          : hasRun
            ? "有运行记录，但读不到结果文件，指标先按空值显示。"
            : "还没有正式运行。跑完后这里会填入最优值、评估次数、域规模和下一批推荐。";

  return (
    <div className="mx-auto w-full max-w-[1500px] px-4 py-6 lg:px-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">分析视图</h1>
          <p className="mt-1 text-sm text-muted-foreground">{headerHint}</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge variant="outline" className="num">
            {hasResult || demoMode ? `${charts.n} 条实验 · ${charts.target}` : "暂无结果"}
          </Badge>
          <Link to="/projects" className="text-xs text-muted-foreground underline-offset-4 hover:underline">
            从课题列表选择
          </Link>
        </div>
      </div>

      {error && <p className="mt-4 text-sm text-destructive">{error}</p>}
      {isFailed && runInfo.error && (
        <p className="mt-4 text-sm text-destructive">失败原因：{runInfo.error}</p>
      )}

      <section className="panel mt-6 p-5">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-base font-semibold">运行详情</h2>
            <span className="num text-xs text-muted-foreground">{runInfo.uuid ?? uuid ?? "暂无运行"}</span>
          </div>
          <dl className="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 border-t border-border pt-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
            <div>
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">状态</dt>
              <dd className={`mt-0.5 font-semibold ${isFailed ? "text-destructive" : ""}`}>{statusLabel}</dd>
            </div>
            <div>
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">引擎 / 目标</dt>
              <dd className="mt-0.5 font-semibold">
                {(runInfo.engine || "暂无").toString().toUpperCase()} · {hasRun ? detail.target : "暂无"}
              </dd>
            </div>
            <div>
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">批量 / 迭代</dt>
              <dd className="mt-0.5 font-semibold">
                {hasRun ? `${cell(detail.batchSize)} / ${cell(detail.iterations)}` : "暂无"}
              </dd>
            </div>
            <div>
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">参数数量</dt>
              <dd className="mt-0.5 font-semibold">
                {hasRun ? detail.parameters.length || charts.names.length || 0 : "暂无"}
              </dd>
            </div>
            <div>
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">先验数据</dt>
              <dd className="mt-0.5 font-semibold">{hasRun ? detail.prior : "暂无"}</dd>
            </div>
            <div>
              <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">运行时间</dt>
              <dd className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                开始 {runInfo.started_at ?? "暂无"}
                <br />
                结束 {runInfo.finished_at ?? (isPending ? "尚未结束" : "暂无")}
              </dd>
            </div>
          </dl>

          <div className="mt-5">
            <h3 className="text-sm font-semibold">参数空间</h3>
            <div className="mt-2 flex flex-wrap gap-2">
              {(detail.parameters.length ? detail.parameters : charts.names.map((name) => ({ name }))).map((param) => (
                <span
                  key={param.name}
                  className="inline-flex max-w-full items-center gap-1.5 rounded-md bg-muted px-2.5 py-1 text-xs"
                >
                  <span className="font-medium">{param.name}</span>
                  {param.values ? (
                    <span className="break-all text-muted-foreground">{formatParamValues(param)}</span>
                  ) : null}
                </span>
              ))}
            </div>
          </div>

          <div className="mt-5">
            <h3 className="text-sm font-semibold">运行结果</h3>
            <p className="mt-1 text-xs text-muted-foreground">{resultIntro}</p>
            <dl className="mt-3 grid grid-cols-3 gap-x-5 gap-y-3 border-t border-border pt-4 text-sm">
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">
                  {charts.target === "yield" ? "最优产率" : `最优 ${charts.target}`}
                </dt>
                <dd className="mt-0.5 font-semibold">{metricBest}</dd>
              </div>
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">评估次数</dt>
                <dd className="mt-0.5 font-semibold">{metricEvals}</dd>
              </div>
              <div>
                <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">域规模</dt>
                <dd className="mt-0.5 font-semibold">{metricDomain}</dd>
              </div>
            </dl>
          </div>

          {charts.predicted.length > 0 && (
            <div className="mt-5">
              <h3 className="text-sm font-semibold">推荐下一批实验</h3>
              <div className="mt-2 overflow-x-auto rounded-md border border-border">
                <Table>
                  <TableHeader>
                    <TableRow className="bg-surface/70">
                      {detail.predKeys.map((key) => (
                        <TableHead key={key}>{predColLabel(key, charts.target)}</TableHead>
                      ))}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {charts.predicted.map((row, i) => (
                      <TableRow key={i}>
                        {detail.predKeys.map((key) => (
                          <TableCell key={key} className="num">
                            {cell(row[key])}
                          </TableCell>
                        ))}
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}
          {!hasResult && !isPending && (
            <p className="mt-4 rounded-md border border-dashed border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
              推荐下一批实验：暂无。{isFailed ? "失败运行不会生成推荐表。" : "有成功结果后会在这里列出参数组合。"}
            </p>
          )}
        </section>

      <Tabs defaultValue="best" className="mt-6">
        <TabsList>
          <TabsTrigger value="best">最优条件</TabsTrigger>
          <TabsTrigger value="records">全部实验</TabsTrigger>
          <TabsTrigger value="convergence">收敛</TabsTrigger>
          <TabsTrigger value="importance">参数重要性</TabsTrigger>
          <TabsTrigger value="slice">参数切片</TabsTrigger>
          <TabsTrigger value="pareto">Pareto 前沿</TabsTrigger>
        </TabsList>

        <TabsContent value="best" className="mt-4 space-y-4">
          <section className="panel p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 className="text-base font-semibold">当前最优组合</h2>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {!hasResult
                    ? vacancy("best")
                    : charts.paretoRows.length > 1
                      ? "多目标时下列为 Pareto 解集中目标值最高的一条；完整折中解见下方表格。"
                      : "已完成实验里目标值最高的一组参数。"}
                </p>
              </div>
              {charts.best && (
                <Badge className="num">
                  {charts.target} {cell(charts.best[charts.target])}
                </Badge>
              )}
            </div>
            {charts.best ? (
              <div className="mt-4 flex flex-wrap gap-2">
                {charts.names.map((name) => (
                  <span key={name} className="rounded-lg border border-border bg-surface/70 px-3 py-1.5 text-sm">
                    {name}：<span className="num font-medium">{cell(charts.best?.[name])}</span>
                  </span>
                ))}
                {charts.objectives
                  .map((o) => o.name)
                  .filter((n): n is string => Boolean(n) && n !== charts.target)
                  .map((name) => (
                    <span key={name} className="rounded-lg border border-border bg-surface/70 px-3 py-1.5 text-sm">
                      {name}：<span className="num font-medium">{cell(charts.best?.[name])}</span>
                    </span>
                  ))}
              </div>
            ) : (
              <p className="mt-4 text-sm text-muted-foreground">{vacancy("best")}</p>
            )}
          </section>

          {charts.paretoRows.length > 0 && (
            <section className="panel overflow-x-auto p-5">
              <h2 className="text-base font-semibold">Pareto 最优解集</h2>
              <p className="mt-0.5 text-xs text-muted-foreground">互不支配的条件，需按工艺偏好取舍。</p>
              <Table className="mt-3">
                <TableHeader>
                  <TableRow className="bg-surface/70">
                    {charts.names.map((name) => (
                      <TableHead key={name}>{name}</TableHead>
                    ))}
                    {(charts.objectives.length ? charts.objectives.map((o) => o.name).filter(Boolean) : [charts.target]).map(
                      (name) => (
                        <TableHead key={name} className="text-right">
                          {name}
                        </TableHead>
                      ),
                    )}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {charts.paretoRows.map((row, i) => (
                    <TableRow key={i}>
                      {charts.names.map((name) => (
                        <TableCell key={name} className="num">
                          {cell(row[name])}
                        </TableCell>
                      ))}
                      {(charts.objectives.length
                        ? charts.objectives.map((o) => o.name).filter((n): n is string => Boolean(n))
                        : [charts.target]
                      ).map((name) => (
                        <TableCell key={name} className="num text-right font-medium">
                          {cell(row[name])}
                        </TableCell>
                      ))}
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </section>
          )}
        </TabsContent>

        <TabsContent value="records" className="mt-4">
          <section className="panel overflow-x-auto p-5">
            <div className="flex flex-wrap items-end justify-between gap-2">
              <div>
                <h2 className="text-base font-semibold">全部评估实验</h2>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  本课题已记录的参数组合与实测目标。高亮行为当前最优。
                </p>
              </div>
              <Badge variant="outline" className="num">
                {charts.records.length} 条
              </Badge>
            </div>
            {charts.records.length === 0 ? (
              <p className="mt-6 text-center text-sm text-muted-foreground">{vacancy("records")}</p>
            ) : (
              <Table className="mt-3">
                <TableHeader>
                  <TableRow className="bg-surface/70">
                    <TableHead className="w-10">#</TableHead>
                    <TableHead className="w-16">批次</TableHead>
                    {charts.names.map((name) => (
                      <TableHead key={name}>{name}</TableHead>
                    ))}
                    <TableHead className="text-right">{charts.target}</TableHead>
                    {charts.objectives
                      .map((o) => o.name)
                      .filter((n): n is string => Boolean(n) && n !== charts.target)
                      .map((name) => (
                        <TableHead key={name} className="text-right">
                          {name}
                        </TableHead>
                      ))}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {charts.records.map((row, i) => {
                    const isBest =
                      charts.best != null &&
                      charts.names.every((name) => String(row[name] ?? "") === String(charts.best?.[name] ?? "")) &&
                      String(row[charts.target] ?? "") === String(charts.best[charts.target] ?? "");
                    return (
                      <TableRow key={i} className={isBest ? "bg-accent/50" : undefined}>
                        <TableCell className="num text-muted-foreground">{i + 1}</TableCell>
                        <TableCell className="num text-muted-foreground">{cell(row.batch)}</TableCell>
                        {charts.names.map((name) => (
                          <TableCell key={name} className="num">
                            {cell(row[name])}
                          </TableCell>
                        ))}
                        <TableCell className="num text-right font-medium">{cell(row[charts.target])}</TableCell>
                        {charts.objectives
                          .map((o) => o.name)
                          .filter((n): n is string => Boolean(n) && n !== charts.target)
                          .map((name) => (
                            <TableCell key={name} className="num text-right">
                              {cell(row[name])}
                            </TableCell>
                          ))}
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            )}
          </section>
        </TabsContent>

        <TabsContent value="convergence" className="mt-4 grid gap-6 lg:grid-cols-2">
          <Panel title="最优值收敛" hint="每一步的最优目标值与均值（来自 results.convergence）" empty={charts.conv.length === 0} emptyText={vacancy("chart")}>
            <LineChart data={charts.conv} margin={{ top: 8, right: 12, left: -16, bottom: 8 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
              <XAxis dataKey="batch" {...axis} />
              <YAxis {...axis} />
              <RTooltip {...tooltipStyle} />
              <Legend {...legendProps} />
              <Line type="monotone" dataKey="best" name="累计最优" stroke="var(--color-chart-1)" strokeWidth={2} dot={{ r: 3 }} />
              <Line
                type="monotone"
                dataKey="mean"
                name="当步均值"
                stroke="var(--color-chart-2)"
                strokeWidth={2}
                strokeDasharray="4 4"
                dot={{ r: 3 }}
              />
            </LineChart>
          </Panel>

          <Panel title="批次均值" hint="逐步评估后的均值轨迹" empty={charts.conv.length === 0} emptyText={vacancy("chart")}>
            <AreaChart data={charts.conv} margin={{ top: 8, right: 12, left: -16, bottom: 8 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
              <XAxis dataKey="batch" {...axis} />
              <YAxis {...axis} />
              <RTooltip {...tooltipStyle} />
              <Legend {...legendProps} />
              <Area
                type="monotone"
                dataKey="mean"
                name="批次均值"
                stroke="var(--color-chart-3)"
                fill="var(--color-chart-3)"
                fillOpacity={0.15}
                strokeWidth={2}
              />
            </AreaChart>
          </Panel>
        </TabsContent>

        <TabsContent value="importance" className="mt-4">
          <Panel
            title="参数重要性（经验）"
            hint="按该参数取值分组后，目标均值极差的相对占比。不是 GP 长度尺度。"
            empty={charts.importance.length === 0}
            emptyText={vacancy("chart")}
          >
            <BarChart data={charts.importance} layout="vertical" margin={{ top: 8, right: 16, left: 24, bottom: 8 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" horizontal={false} />
              <XAxis type="number" {...axis} unit="%" />
              <YAxis type="category" dataKey="name" width={110} {...axis} />
              <RTooltip {...tooltipStyle} />
              <Legend {...legendProps} />
              <Bar dataKey="value" name="相对影响（%）" fill="var(--color-chart-1)" radius={[0, 4, 4, 0]} />
            </BarChart>
          </Panel>
        </TabsContent>

        <TabsContent value="slice" className="mt-4">
          <Panel
            title={`参数切片：${charts.sliceName || "暂无参数"}`}
            hint="已完成实验在该参数上的实测散点折线（非 GP 后验切片）"
            empty={charts.slice.length === 0}
            emptyText={vacancy("chart")}
          >
            <LineChart data={charts.slice} margin={{ top: 8, right: 12, left: -16, bottom: 8 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" vertical={false} />
              <XAxis dataKey="temp" name={charts.sliceName || "参数"} {...axis} />
              <YAxis name={charts.target} {...axis} />
              <RTooltip {...tooltipStyle} />
              <Legend {...legendProps} />
              <Line type="monotone" dataKey="mean" name="实测观测" stroke="var(--color-chart-1)" strokeWidth={2} dot={{ r: 2 }} />
            </LineChart>
          </Panel>
        </TabsContent>

        <TabsContent value="pareto" className="mt-4">
          <Panel
            title="Pareto 前沿"
            hint="多目标运行会返回 pareto_front；单目标时仅展示实验点"
            empty={charts.paretoFront.length === 0 && charts.paretoRest.length === 0}
            emptyText={vacancy("chart")}
          >
            <ScatterChart margin={{ top: 8, right: 12, left: -16, bottom: 8 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
              <XAxis type="number" dataKey="cost" name="第二目标" {...axis} />
              <YAxis type="number" dataKey="yield" name={charts.target} {...axis} />
              <RTooltip {...tooltipStyle} cursor={{ strokeDasharray: "3 3" }} />
              <Legend {...legendProps} />
              <Scatter
                name="其它实验点（被支配）"
                data={charts.paretoRest}
                fill="var(--color-muted-foreground)"
                legendType="circle"
              />
              <Scatter
                name="Pareto 前沿"
                data={charts.paretoFront}
                fill="var(--color-chart-1)"
                legendType="circle"
              />
            </ScatterChart>
          </Panel>
        </TabsContent>
      </Tabs>
    </div>
  );
}
