import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect, useRef, useState } from "react";
import { Loader2, Play, Plus, Save, Trash2, Download, FilePlus2 } from "lucide-react";
import { InfoHint } from "@/components/info-hint";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";
import { Textarea } from "@/components/ui/textarea";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import {
  acquisitions,
  defaultParams,
  encodingLabel,
  initMethods,
  type Encoding,
  type ParamRow,
} from "@/lib/edbo-data";
import { buildDemoShowcase } from "@/lib/demo-showcase";
import { fetchRun, fetchTask, runOptimize, type RunDetail } from "@/lib/api";
import { useAppMode } from "@/lib/app-mode";
import {
  UNNAMED,
  normalizeResult,
  paramsFromConfig,
  persistFormalSession,
  readFormalSession,
} from "@/lib/run-hydrate";

type Search = { task?: string };

export const Route = createFileRoute("/")({
  validateSearch: (search: Record<string, unknown>): Search => ({
    task: typeof search.task === "string" && search.task !== "" ? search.task : undefined,
  }),
  head: () => ({
    meta: [
      { title: "优化工作台 · EDBO Web" },
      {
        name: "description",
        content: "配置实验参数与优化目标，运行贝叶斯优化并获得下一批实验推荐条件。",
      },
      { property: "og:title", content: "优化工作台 · EDBO Web" },
      { property: "og:description", content: "配置参数、运行优化、获得下一批实验推荐。" },
    ],
  }),
  component: Workbench,
});

const secondObjectiveOptions: { value: string; label: string }[] = [
  { value: "cost", label: "成本" },
  { value: "time", label: "反应时间" },
  { value: "impurity", label: "杂质" },
];

function stripPredicted(row: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(row)) {
    if (k.startsWith("predicted_")) continue;
    out[k] = v;
  }
  return out;
}

type ModeSnap = {
  result: Record<string, unknown> | null;
  taskUuid: string | null;
  observed: Record<string, string>;
  observedSecond: Record<string, string>;
  projectName: string;
  demoRound: number;
};

const emptySnap = (name: string): ModeSnap => ({
  result: null,
  taskUuid: null,
  observed: {},
  observedSecond: {},
  projectName: name,
  demoRound: 0,
});

function Workbench() {
  const navigate = useNavigate({ from: "/" });
  const { task: searchTask } = Route.useSearch();
  const { demoMode } = useAppMode();
  const hydrated = useRef<string | null>(null);
  const [params, setParams] = useState<ParamRow[]>(defaultParams);
  const [engine, setEngine] = useState("ax");
  const [objective, setObjective] = useState("single");
  const [batch, setBatch] = useState("5");
  const [rounds, setRounds] = useState("3");
  const [demoRound, setDemoRound] = useState(0);
  const [formalSnap, setFormalSnap] = useState<ModeSnap>(emptySnap("未命名课题"));
  const [demoSnap, setDemoSnap] = useState<ModeSnap>(emptySnap("Suzuki 偶联演示"));
  const [acquisition, setAcquisition] = useState("EI");
  const [initMethod, setInitMethod] = useState("rand");
  const [priorCsv, setPriorCsv] = useState("");
  const [observed, setObserved] = useState<Record<string, string>>({});
  const [observedSecond, setObservedSecond] = useState<Record<string, string>>({});
  const [projectName, setProjectName] = useState("未命名课题");
  const [running, setRunning] = useState(false);
  const [rerunning, setRerunning] = useState(false);
  const [runHint, setRunHint] = useState("正在提交优化任务…");
  const [elapsed, setElapsed] = useState(0);
  const [taskUuid, setTaskUuid] = useState<string | null>(null);
  const [result, setResult] = useState<Record<string, unknown> | null>(null);

  const [open, setOpen] = useState(false);
  const [draftName, setDraftName] = useState("");
  const [draftEngine, setDraftEngine] = useState("ax");
  const [draftObjective, setDraftObjective] = useState("single");
  const [draftSecond, setDraftSecond] = useState("cost");
  const [secondObjective, setSecondObjective] = useState("cost");

  const update = (id: string, patch: Partial<ParamRow>) =>
    setParams((rows) => rows.map((r) => (r.id === id ? { ...r, ...patch } : r)));

  const addParam = () =>
    setParams((rows) => [
      ...rows,
      { id: `p${Date.now()}`, name: "", encoding: "numeric", values: "" },
    ]);

  const budget = Number(batch) * (Number(rounds) + 1);
  const isMulti = objective === "multi";
  const engineLabel = engine === "ax" ? "Ax" : "MNL";

  const predictedNext = Array.isArray(result?.predicted_next)
    ? (result.predicted_next as Record<string, unknown>[])
    : Array.isArray(result?.recommended_experiments)
      ? (result.recommended_experiments as Record<string, unknown>[])
      : [];
  const paramNames = Array.isArray(result?.parameter_names)
    ? (result.parameter_names as string[])
    : params.map((p) => p.name).filter(Boolean);
  const targetName = typeof result?.target === "string" ? result.target : "yield";
  const predCol = `predicted_${targetName}`;
  const best =
    result?.best && typeof result.best === "object" ? (result.best as Record<string, unknown>) : null;

  const applyResult = (payload: Record<string, unknown>, nextTask: string | null | undefined) => {
    const normalized = normalizeResult(payload) ?? payload;
    setResult(normalized);
    if (!demoMode && nextTask) {
      setTaskUuid(nextTask);
      persistFormalSession(nextTask, projectName);
    }
    setObserved({});
    setObservedSecond({});
    const nextCount = Array.isArray(payload.predicted_next) ? payload.predicted_next.length : 0;
    toast.success(
      demoMode
        ? `这是演示数据（不调用引擎）。已展示 ${nextCount} 条样例推荐`
        : nextCount > 0
          ? `优化完成，生成 ${nextCount} 条下一批推荐`
          : "优化完成（当前最优已写入结果）",
    );
  };

  const loadDemo = (round: number) => {
    const payload = buildDemoShowcase({
      params,
      isMulti,
      secondObjective,
      batchSize: Number(batch) || 5,
      round,
      engine,
    });
    setResult(payload);
    setTaskUuid(null);
    setObserved({});
    setObservedSecond({});
    setDemoRound(round);
  };

  const switchMode = (toFormal: boolean) => {
    const snap: ModeSnap = {
      result,
      taskUuid,
      observed,
      observedSecond,
      projectName,
      demoRound,
    };
    if (!toFormal) {
      setFormalSnap(snap);
    } else {
      setDemoSnap(snap);
    }
    const incoming = toFormal ? formalSnap : demoSnap;
    setResult(incoming.result);
    setTaskUuid(toFormal ? incoming.taskUuid : null);
    setObserved(incoming.observed);
    setObservedSecond(incoming.observedSecond);
    setProjectName(incoming.projectName);
    setDemoRound(incoming.demoRound);
    if (!toFormal && !incoming.result) {
      const payload = buildDemoShowcase({
        params,
        isMulti,
        secondObjective,
        batchSize: Number(batch) || 5,
        round: 0,
        engine,
      });
      setResult(payload);
      setProjectName(incoming.projectName || "Suzuki 偶联演示");
    }
  };

  const applyTask = (run: RunDetail) => {
    const cfg = run.config ?? {};
    const rows = paramsFromConfig(cfg.parameters);
    if (rows.length) setParams(rows);
    if (typeof run.engine === "string" && run.engine) setEngine(run.engine);
    const objs = Array.isArray(cfg.objectives) ? (cfg.objectives as { name?: string }[]) : [];
    if (objs.length > 1) {
      setObjective("multi");
      if (objs[1]?.name) setSecondObjective(objs[1].name);
    } else {
      setObjective("single");
    }
    if (cfg.batch_size != null) setBatch(String(cfg.batch_size));
    if (cfg.iterations != null) setRounds(String(cfg.iterations));
    if (typeof cfg.acquisition_function === "string") setAcquisition(cfg.acquisition_function);
    if (typeof cfg.init_method === "string") setInitMethod(cfg.init_method);
    if (typeof cfg.project_name === "string" && cfg.project_name) setProjectName(cfg.project_name);
    setTaskUuid(run.task_uuid ?? null);
    setResult(normalizeResult(run.result));
    setObserved({});
    setObservedSecond({});
    persistFormalSession(run.task_uuid ?? null, String(cfg.project_name || projectName));
  };

  useEffect(() => {
    if (!running) {
      setElapsed(0);
      return;
    }
    const timer = window.setInterval(() => setElapsed((s) => s + 1), 1000);
    return () => window.clearInterval(timer);
  }, [running]);

  useEffect(() => {
    if (demoMode) return;
    const wanted = searchTask || readFormalSession()?.taskUuid;
    if (!wanted || hydrated.current === wanted) return;
    hydrated.current = wanted;
    void fetchTask(wanted)
      .then((run) => {
        applyTask(run);
        if (!searchTask && run.task_uuid) {
          void navigate({ search: { task: run.task_uuid }, replace: true });
        }
      })
      .catch(() => {
        persistFormalSession(null, "");
        hydrated.current = null;
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [demoMode, searchTask]);

  const prevDemo = useRef(demoMode);
  useEffect(() => {
    if (prevDemo.current === demoMode) return;
    switchMode(!demoMode);
    prevDemo.current = demoMode;
    // eslint-disable-next-line react-hooks/exhaustive-deps -- 仅在顶栏模式切换时搬迁工作台快照
  }, [demoMode]);

  const submitRun = async (reRecommend: boolean) => {
    if (!demoMode && !reRecommend && !taskUuid) {
      const name = projectName.trim();
      if (!name || name === UNNAMED) {
        toast.error("请先给课题起个名字，再运行优化。");
        setOpen(true);
        return;
      }
    }

    if (demoMode) {
      const nextRound = reRecommend ? Math.min(2, demoRound + 1) : 0;
      loadDemo(nextRound);
      toast.success(
        reRecommend
          ? "演示：已给出下一批样例条件（未连接 Python 引擎）"
          : "演示：已载入样例推荐与收敛曲线",
      );
      return;
    }

    if (reRecommend) {
      const missing = predictedNext.some((_, i) => !observed[String(i)]?.trim());
      if (missing) {
        toast.error("请为每一条推荐填入实测目标值后再重新推荐。");
        return;
      }
      if (isMulti) {
        const missing2 = predictedNext.some((_, i) => !observedSecond[String(i)]?.trim());
        if (missing2) {
          toast.error(`多目标模式请同时填入「${secondObjective}」实测值。`);
          return;
        }
      }
    }

    setRunning(true);
    setRerunning(reRecommend);
    setRunHint(reRecommend ? "正在根据实测结果重新推荐…" : "正在提交优化任务…");
    try {
      const priorResults = reRecommend
        ? predictedNext.map((row, i) => {
            const cleaned = stripPredicted(row);
            const next: Record<string, unknown> = {
              ...cleaned,
              [targetName]: Number(observed[String(i)]),
            };
            if (isMulti) {
              next[secondObjective] = Number(observedSecond[String(i)]);
            }
            return next;
          })
        : [];

      const data = await runOptimize({
        engine,
        demo_mode: false,
        multi_objective: isMulti,
        second_objective: secondObjective,
        objectives: isMulti
          ? [
              { name: "yield", minimize: false },
              { name: secondObjective, minimize: true },
            ]
          : [{ name: "yield", minimize: false }],
        batch_size: Number(batch) || 5,
        iterations: Number(rounds) || 3,
        acquisition,
        init_method: initMethod,
        parameters: params,
        project_name: projectName,
        task_uuid: taskUuid,
        prior_data: reRecommend ? "" : priorCsv,
        prior_results: priorResults,
      });
      if (!data.ok) {
        toast.error(data.error || "运行失败");
        return;
      }
      if (data.task_uuid) {
        setTaskUuid(data.task_uuid);
        persistFormalSession(data.task_uuid, projectName);
        void navigate({ search: { task: data.task_uuid }, replace: true });
      }
      if (data.result) {
        applyResult(data.result, data.task_uuid);
      } else if (data.uuid) {
        setRunHint("任务已提交，引擎正在计算下一批条件…");
        toast.message("任务已提交，正在等待结果…");
        for (let i = 0; i < 90; i++) {
          await new Promise((r) => setTimeout(r, 2000));
          const poll = await fetchRun(data.uuid);
          if (poll.status === "completed" && poll.result) {
            applyResult(poll.result, poll.task_uuid ?? data.task_uuid);
            break;
          }
          if (poll.status === "failed") {
            toast.error(poll.error || "优化失败");
            break;
          }
        }
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "运行失败");
    } finally {
      setRunning(false);
      setRerunning(false);
    }
  };

  const createProject = () => {
    const name = draftName.trim();
    if (!name) {
      toast.error("请填写课题名称。");
      return;
    }
    setProjectName(name);
    setEngine(draftEngine);
    setObjective(draftObjective);
    setSecondObjective(draftSecond);
    setParams(defaultParams);
    setObserved({});
    setObservedSecond({});
    setPriorCsv("");
    setOpen(false);
    if (demoMode) {
      setTaskUuid(null);
      setDemoRound(0);
      setResult(
        buildDemoShowcase({
          params: defaultParams,
          isMulti: draftObjective === "multi",
          secondObjective: draftSecond,
          batchSize: Number(batch) || 5,
          round: 0,
          engine: draftEngine,
        }),
      );
    } else {
      setResult(null);
      setTaskUuid(null);
      persistFormalSession(null, "");
      hydrated.current = null;
      void navigate({ search: {}, replace: true });
    }
    toast.success(
      `已新建${demoMode ? "演示" : ""}课题：${name} · ${
        draftObjective === "multi" ? `多目标（产率 / ${draftSecond}）` : "单目标"
      }`,
    );
  };

  const exportCsv = () => {
    const rows = predictedNext.length > 0 ? predictedNext : best ? [best] : [];
    if (rows.length === 0) {
      toast.error("暂无可导出的推荐结果。");
      return;
    }
    const headers = [...paramNames, predCol, targetName];
    const lines = [
      headers.join(","),
      ...rows.map((row, i) =>
        headers
          .map((h) => {
            if (h === targetName) return observed[String(i)] ?? "";
            return String(row[h] ?? "");
          })
          .join(","),
      ),
    ];
    const blob = new Blob(["\uFEFF" + lines.join("\n")], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${projectName || "edbo"}-next.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const csvHint = `表头须包含：${params.map((p) => p.name).filter(Boolean).join(", ") || "各参数名"}, yield${isMulti ? `, ${secondObjective}` : ""}`;

  return (
    <div className="mx-auto w-full max-w-[1500px] px-4 py-6 lg:px-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-2xl font-semibold">优化工作台</h1>
            <InfoHint label="工作台说明">
              {demoMode
                ? "演示模式展示样例数据，不调用 Python。课题列表与分析视图会同步演示样例，与正式运行隔离。"
                : "正式模式会调用 Ax/MNL 做真实参数推荐，同一课题的实测结果会累积后再推荐下一批。"}
            </InfoHint>
          </div>
          <Input
            className="mt-1 h-8 max-w-xs text-sm"
            value={projectName}
            placeholder="课题名称"
            onChange={(e) => setProjectName(e.target.value)}
          />
          {taskUuid && !demoMode ? (
            <p className="mt-1 text-[11px] text-muted-foreground">
              已绑定课题 <span className="num">{taskUuid.slice(0, 8)}</span>，再次运行会续在这一题上
            </p>
          ) : null}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant="secondary" className="num">
            评估预算 {budget}
          </Badge>
          <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
              <Button variant="outline" size="sm">
                <FilePlus2 className="size-4" /> 新建课题
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
              <DialogHeader>
                <DialogTitle>新建优化课题</DialogTitle>
                <DialogDescription>
                  选择优化引擎与目标模式；多目标模式会同时建模第二个指标并给出 Pareto 前沿。
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="space-y-1.5">
                  <Label className="text-xs text-muted-foreground">课题名称</Label>
                  <Input
                    value={draftName}
                    placeholder="例如：光催化剂筛选（产率 / 成本）"
                    onChange={(e) => setDraftName(e.target.value)}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs text-muted-foreground">优化引擎</Label>
                  <Select value={draftEngine} onValueChange={setDraftEngine}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="ax">Ax / BoTorch</SelectItem>
                      <SelectItem value="mnl">MNL 离散选择</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs text-muted-foreground">目标模式</Label>
                  <Select value={draftObjective} onValueChange={setDraftObjective}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="single">单目标 · 最大化产率</SelectItem>
                      <SelectItem value="multi">多目标 · Pareto 前沿</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                {draftObjective === "multi" && (
                  <div className="space-y-1.5">
                    <Label className="text-xs text-muted-foreground">第二目标（最小化）</Label>
                    <Select value={draftSecond} onValueChange={setDraftSecond}>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {secondObjectiveOptions.map((o) => (
                          <SelectItem key={o.value} value={o.value}>
                            {o.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}
              </div>
              <DialogFooter>
                <Button variant="ghost" onClick={() => setOpen(false)}>
                  取消
                </Button>
                <Button onClick={createProject}>
                  <Plus className="size-4" /> 创建课题
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
          <Button variant="outline" size="sm" onClick={exportCsv}>
            <Download className="size-4" /> 导出 CSV
          </Button>
          <Button size="sm" disabled={running} onClick={() => void submitRun(false)}>
            {running ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />}
            {running ? "运行中…" : demoMode ? "载入演示数据" : "运行优化"}
          </Button>
        </div>
      </div>

      {running && (
        <div className="mt-4 rounded-md border border-primary/30 bg-primary/5 px-4 py-3">
          <div className="flex items-start gap-3">
            <Loader2 className="mt-0.5 size-5 shrink-0 animate-spin text-primary" />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium">{runHint}</p>
              <p className="mt-0.5 text-xs text-muted-foreground">
                已用时 {elapsed} 秒。首轮 Ax 通常需要十几秒到一两分钟，请不要关闭或刷新页面。
              </p>
              <Progress value={Math.min(92, 10 + elapsed * 2)} className="mt-3 h-2" />
            </div>
          </div>
        </div>
      )}

      <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <section className="panel p-5">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-1">
              <h2 className="text-base font-semibold">参数空间</h2>
              <InfoHint label="参数空间填写说明">
                数值（numeric）填写下限、上限，例如 80, 160。类别（ohe）或化学描述符（resolve）用英文逗号分隔候选值；resolve 支持 SMILES 或化合物名。
              </InfoHint>
            </div>
            <Button variant="outline" size="sm" onClick={addParam}>
              <Plus className="size-4" /> 添加参数
            </Button>
          </div>

          <div className="mt-4 space-y-3">
            {params.map((p) => (
              <div
                key={p.id}
                className="grid grid-cols-1 gap-2 rounded-md border border-border bg-surface/60 p-3 sm:grid-cols-[minmax(0,1fr)_170px_minmax(0,1.4fr)_auto]"
              >
                <div className="space-y-1">
                  <Label className="text-xs text-muted-foreground">参数名</Label>
                  <Input
                    value={p.name}
                    placeholder="例如：反应温度"
                    onChange={(e) => update(p.id, { name: e.target.value })}
                  />
                </div>
                <div className="space-y-1">
                  <Label className="text-xs text-muted-foreground">编码</Label>
                  <Select value={p.encoding} onValueChange={(v) => update(p.id, { encoding: v as Encoding })}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {(Object.keys(encodingLabel) as Encoding[]).map((k) => (
                        <SelectItem key={k} value={k}>
                          {encodingLabel[k]}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1">
                  <Label className="text-xs text-muted-foreground">
                    {p.encoding === "numeric" ? "取值范围（下限, 上限）" : "候选取值"}
                  </Label>
                  <Input
                    className="num text-[13px]"
                    value={p.values}
                    onChange={(e) => update(p.id, { values: e.target.value })}
                  />
                </div>
                <div className="flex items-end justify-end">
                  <Button
                    variant="ghost"
                    size="icon"
                    aria-label="删除参数"
                    onClick={() => setParams((rows) => rows.filter((row) => row.id !== p.id))}
                  >
                    <Trash2 className="size-4 text-muted-foreground" />
                  </Button>
                </div>
              </div>
            ))}
          </div>

          <Separator className="my-5" />

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">优化引擎</Label>
              <Select value={engine} onValueChange={setEngine}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="ax">Ax / BoTorch</SelectItem>
                  <SelectItem value="mnl">MNL 离散选择</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">优化目标</Label>
              <Select value={objective} onValueChange={setObjective}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="single">单目标 · 最大化产率</SelectItem>
                  <SelectItem value="multi">多目标 · 产率 / 第二指标</SelectItem>
                </SelectContent>
              </Select>
            </div>
            {isMulti && (
              <div className="space-y-1.5">
                <Label className="text-xs text-muted-foreground">第二目标（最小化）</Label>
                <Select value={secondObjective} onValueChange={setSecondObjective}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {secondObjectiveOptions.map((o) => (
                      <SelectItem key={o.value} value={o.value}>
                        {o.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">批量大小</Label>
              <Input className="num" value={batch} onChange={(e) => setBatch(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">迭代轮数</Label>
              <Input className="num" value={rounds} onChange={(e) => setRounds(e.target.value)} />
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">采集函数</Label>
              <Select value={acquisition} onValueChange={setAcquisition}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.entries(acquisitions).map(([k, label]) => (
                    <SelectItem key={k} value={k}>
                      {label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">初始化方法</Label>
              <Select value={initMethod} onValueChange={setInitMethod}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.entries(initMethods).map(([k, label]) => (
                    <SelectItem key={k} value={k}>
                      {label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="mt-4 space-y-1.5">
            <div className="flex items-center gap-1">
              <Label className="text-xs text-muted-foreground">先验实验 CSV</Label>
              <InfoHint label="先验 CSV 说明">
                {csvHint}。仅首轮提交；正式课题在首轮之后改由实测回填累积，此处会锁定。离散组合数须不少于 批量 ×（迭代 + 1）+ 先验条数，且同一参数组合只推荐一次。
              </InfoHint>
            </div>
            <Textarea
              className="num min-h-[88px] text-[12px]"
              placeholder={csvHint}
              value={priorCsv}
              onChange={(e) => setPriorCsv(e.target.value)}
              disabled={demoMode || Boolean(taskUuid)}
            />
          </div>
        </section>

        <section className="panel p-5">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-1">
              <h2 className="text-base font-semibold">下一批推荐条件</h2>
              <InfoHint label="推荐条件说明">
                {predictedNext.length > 0
                  ? demoMode
                    ? "当前为演示样例。点击「下一批演示」可切换到下一批样例条件。"
                    : "在表格右侧填写实测值，再点击「保存并重新推荐」，进入下一轮贝叶斯优化。"
                  : result
                    ? "本次评估已覆盖当前离散域，下面展示模型给出的当前最优条件。"
                    : demoMode
                      ? "点击「载入演示数据」查看样例推荐，不会调用优化引擎。"
                      : "点击「运行优化」后，这里会显示后端返回的下一批实验条件。演示请把右上角开关拨到左侧。"}
              </InfoHint>
            </div>
            <Badge variant="outline" className="num">
              {engineLabel} · {isMulti ? "多目标 Pareto" : "单目标"} · {demoMode ? "演示" : "正式"}
            </Badge>
          </div>

          <div className="mt-4 overflow-x-auto rounded-md border border-border">
            <Table>
              <TableHeader>
                <TableRow className="bg-surface/70">
                  <TableHead className="w-10">#</TableHead>
                  {paramNames.map((name) => (
                    <TableHead key={name}>{name}</TableHead>
                  ))}
                  <TableHead className="text-right">预测{targetName}</TableHead>
                  <TableHead className="w-28">实测 {targetName}</TableHead>
                  {isMulti && <TableHead className="w-28">实测 {secondObjective}</TableHead>}
                </TableRow>
              </TableHeader>
              <TableBody>
                {predictedNext.length === 0 && best ? (
                  <TableRow>
                    <TableCell className="num text-muted-foreground">最优</TableCell>
                    {paramNames.map((name) => (
                      <TableCell key={name} className="num">
                        {String(best[name] ?? "—")}
                      </TableCell>
                    ))}
                    <TableCell className="text-right">
                      <span className="num font-medium">{String(best[targetName] ?? "—")}</span>
                    </TableCell>
                    <TableCell className="text-xs text-muted-foreground">已评估</TableCell>
                    {isMulti && <TableCell className="text-xs text-muted-foreground">—</TableCell>}
                  </TableRow>
                ) : predictedNext.length === 0 ? (
                  <TableRow>
                    <TableCell
                      colSpan={paramNames.length + (isMulti ? 4 : 3)}
                      className="py-10 text-center text-sm text-muted-foreground"
                    >
                      {running ? (
                        <div className="flex flex-col items-center gap-3">
                          <Loader2 className="size-8 animate-spin text-primary" />
                          <div>
                            <p className="font-medium text-foreground">正在生成下一批推荐</p>
                            <p className="mt-1 text-xs">{runHint}</p>
                          </div>
                        </div>
                      ) : (
                        "尚未运行"
                      )}
                    </TableCell>
                  </TableRow>
                ) : (
                  predictedNext.map((row, i) => (
                    <TableRow key={i}>
                      <TableCell className="num text-muted-foreground">{i + 1}</TableCell>
                      {paramNames.map((name) => (
                        <TableCell key={name} className="num">
                          {String(row[name] ?? "—")}
                        </TableCell>
                      ))}
                      <TableCell className="text-right">
                        <span className="num font-medium">{row[predCol] != null ? `${row[predCol]}` : "—"}</span>
                      </TableCell>
                      <TableCell>
                        <Input
                          className="num h-8 text-[13px]"
                          placeholder="—"
                          value={observed[String(i)] ?? ""}
                          onChange={(e) => setObserved((o) => ({ ...o, [String(i)]: e.target.value }))}
                        />
                      </TableCell>
                      {isMulti && (
                        <TableCell>
                          <Input
                            className="num h-8 text-[13px]"
                            placeholder="—"
                            value={observedSecond[String(i)] ?? ""}
                            onChange={(e) => setObservedSecond((o) => ({ ...o, [String(i)]: e.target.value }))}
                          />
                        </TableCell>
                      )}
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </div>

          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-xs text-muted-foreground">
              {taskUuid ? (
                <>
                  课题 <span className="num text-foreground">{taskUuid.slice(0, 8)}</span>
                  {best?.[targetName] != null && (
                    <>
                      {" "}
                      · 当前最优 {targetName}{" "}
                      <span className="num text-foreground">{String(best[targetName])}</span>
                    </>
                  )}
                </>
              ) : (
                "尚未绑定后端课题"
              )}
            </p>
            <Button variant="secondary" disabled={running || predictedNext.length === 0} onClick={() => void submitRun(true)}>
              {rerunning ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              {rerunning ? "重新推荐中…" : demoMode ? "下一批演示" : "保存并重新推荐"}
            </Button>
          </div>
        </section>
      </div>
    </div>
  );
}
