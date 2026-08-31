import { createFileRoute } from "@tanstack/react-router";
import { useEffect, useRef, useState } from "react";
import { Play, Plus, Save, Trash2, Info, Download, FilePlus2 } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
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
import { fetchRun, runOptimize } from "@/lib/api";
import { useAppMode } from "@/lib/app-mode";

export const Route = createFileRoute("/")({
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
  const { demoMode } = useAppMode();
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
    : [];
  const paramNames = Array.isArray(result?.parameter_names)
    ? (result.parameter_names as string[])
    : params.map((p) => p.name).filter(Boolean);
  const targetName = typeof result?.target === "string" ? result.target : "yield";
  const predCol = `predicted_${targetName}`;
  const best =
    result?.best && typeof result.best === "object" ? (result.best as Record<string, unknown>) : null;

  const applyResult = (payload: Record<string, unknown>, nextTask: string | null | undefined) => {
    setResult(payload);
    if (!demoMode && nextTask) setTaskUuid(nextTask);
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

  const prevDemo = useRef(demoMode);
  useEffect(() => {
    if (prevDemo.current === demoMode) return;
    switchMode(!demoMode);
    prevDemo.current = demoMode;
    // eslint-disable-next-line react-hooks/exhaustive-deps -- 仅在顶栏模式切换时搬迁工作台快照
  }, [demoMode]);

  const submitRun = async (reRecommend: boolean) => {
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
      if (data.task_uuid) setTaskUuid(data.task_uuid);
      if (data.result) {
        applyResult(data.result, data.task_uuid);
      } else if (data.uuid) {
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
    }
  };

  const createProject = () => {
    const name = draftName.trim() || (demoMode ? "Suzuki 偶联演示" : "未命名课题");
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
          <h1 className="text-2xl font-semibold">优化工作台</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            当前课题：{projectName} ·{" "}
            {demoMode
              ? "演示模式（样例数据）。课题列表与分析视图同步展示演示样例，与正式运行隔离。"
              : "正式模式（真实参数推荐，课题累积）"}
          </p>
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
            <Play className="size-4" /> {running ? "运行中…" : demoMode ? "载入演示数据" : "运行优化"}
          </Button>
        </div>
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <section className="panel p-5">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-base font-semibold">参数空间</h2>
              <p className="mt-0.5 text-xs text-muted-foreground">
                numeric 填区间；ohe / resolve 填逗号分隔候选值（resolve 支持 SMILES 或化合物名）
              </p>
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
            <Label className="text-xs text-muted-foreground">先验实验 CSV（可选，仅首轮提交）</Label>
            <Textarea
              className="num min-h-[88px] text-[12px]"
              placeholder={csvHint}
              value={priorCsv}
              onChange={(e) => setPriorCsv(e.target.value)}
              disabled={demoMode || Boolean(taskUuid)}
            />
            <p className="text-[11px] text-muted-foreground">{csvHint}。正式课题在首轮之后改由实测回填累积，此处会锁定。</p>
          </div>

          <div className="mt-4 flex items-start gap-2 rounded-md border border-border bg-accent/40 p-3 text-xs leading-relaxed text-accent-foreground">
            <Info className="mt-0.5 size-4 shrink-0" />
            <p>
              {demoMode
                ? "演示模式展示预先设计的高产率样例（约 60–92%）。顶栏切换到正式后才会调用 Python；课题与分析页会跟着切换数据源。"
                : "正式模式会把同一 task_uuid 的实测结果写入 experiment log，再推荐下一批，且同一参数组合只出现一次。域规模守卫：离散组合数必须 ≥ 批量 ×（迭代 + 1）+ 先验条数。"}
            </p>
          </div>
        </section>

        <section className="panel p-5">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-base font-semibold">下一批推荐条件</h2>
              <p className="mt-0.5 text-xs text-muted-foreground">
                {predictedNext.length > 0
                  ? demoMode
                    ? "演示样例 · 可点「保存并重新推荐」切换到下一批更好看的条件"
                    : "回填实测值后点击「保存并重新推荐」，进入下一轮贝叶斯优化"
                  : result
                    ? "本次评估已覆盖当前离散域，下面展示模型给出的当前最优条件"
                    : demoMode
                      ? "点击「载入演示数据」查看样例推荐（不调用引擎）"
                      : "运行优化后，这里会显示后端返回的下一批实验条件"}
              </p>
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
                      className="py-8 text-center text-sm text-muted-foreground"
                    >
                      尚未运行。当前为正式模式：点击「运行优化」会调用 Ax/MNL 做真实参数推荐。演示请把开关拨到左侧。
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
              <Save className="size-4" /> {demoMode ? "下一批演示" : "保存并重新推荐"}
            </Button>
          </div>
        </section>
      </div>
    </div>
  );
}
