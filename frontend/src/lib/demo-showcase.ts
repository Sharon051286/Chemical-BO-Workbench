import type { ParamRow } from "@/lib/edbo-data";
import { defaultParams } from "@/lib/edbo-data";
import type { ApiProject } from "@/lib/api";

export type DemoRound = 0 | 1 | 2;

type DemoPoint = {
  nums: number[];
  cats: string[];
  yield: number;
  predicted: number;
  cost: number;
};

const BATCHES: DemoPoint[][] = [
  [
    { nums: [96, 12, 2.4], cats: ["Cs2CO3", "CC#N"], yield: 81.2, predicted: 78.4, cost: 38 },
    { nums: [84, 18, 3.1], cats: ["K2CO3", "CS(C)=O"], yield: 72.5, predicted: 74.9, cost: 31 },
    { nums: [112, 8, 1.2], cats: ["KOtBu", "C1CCOC1"], yield: 68.1, predicted: 71.2, cost: 44 },
    { nums: [88, 14, 4.0], cats: ["Cs2CO3", "CO"], yield: 64.8, predicted: 66.8, cost: 22 },
    { nums: [104, 10, 0.9], cats: ["Et3N", "CC#N"], yield: 61.4, predicted: 63.5, cost: 19 },
  ],
  [
    { nums: [98, 11, 2.2], cats: ["Cs2CO3", "C1CCOC1"], yield: 86.5, predicted: 84.1, cost: 41 },
    { nums: [108, 9, 1.8], cats: ["Cs2CO3", "CC#N"], yield: 83.0, predicted: 81.6, cost: 36 },
    { nums: [92, 13, 2.8], cats: ["KOtBu", "CS(C)=O"], yield: 79.4, predicted: 77.2, cost: 29 },
    { nums: [118, 7, 1.5], cats: ["K2CO3", "CN(C)C=O"], yield: 75.8, predicted: 74.0, cost: 33 },
    { nums: [86, 16, 3.4], cats: ["Et3N", "C1CCOC1"], yield: 71.1, predicted: 70.5, cost: 24 },
  ],
  [
    { nums: [102, 10, 2.0], cats: ["Cs2CO3", "CC#N"], yield: 91.4, predicted: 89.6, cost: 37 },
    { nums: [110, 8, 1.6], cats: ["Cs2CO3", "C1CCOC1"], yield: 88.7, predicted: 87.2, cost: 40 },
    { nums: [94, 12, 2.6], cats: ["KOtBu", "CC#N"], yield: 85.3, predicted: 84.0, cost: 35 },
    { nums: [106, 9, 1.9], cats: ["K2CO3", "CS(C)=O"], yield: 82.1, predicted: 80.8, cost: 28 },
    { nums: [90, 14, 3.0], cats: ["Cs2CO3", "CN(C)C=O"], yield: 78.6, predicted: 77.4, cost: 32 },
  ],
];

const HISTORY: DemoPoint[] = [
  { nums: [80, 16, 5.0], cats: ["K2CO3", "CO"], yield: 41.2, predicted: 38.0, cost: 18 },
  { nums: [140, 4, 1.0], cats: ["Et3N", "CCCCCC"], yield: 48.6, predicted: 45.1, cost: 26 },
  { nums: [100, 10, 3.0], cats: ["KOtBu", "c1ccccc1"], yield: 55.8, predicted: 52.4, cost: 34 },
  { nums: [90, 8, 2.0], cats: ["Cs2CO3", "CC#N"], yield: 63.1, predicted: 60.2, cost: 30 },
  { nums: [115, 12, 2.5], cats: ["Cs2CO3", "CS(C)=O"], yield: 72.5, predicted: 69.8, cost: 39 },
];

function splitParams(params: ParamRow[]) {
  return {
    numerics: params.filter((p) => p.encoding === "numeric" && p.name.trim()),
    cats: params.filter((p) => p.encoding !== "numeric" && p.name.trim()),
  };
}

function applyPoint(point: DemoPoint, params: ParamRow[]): Record<string, unknown> {
  const { numerics, cats } = splitParams(params);
  const row: Record<string, unknown> = {};
  numerics.forEach((p, i) => {
    row[p.name] = point.nums[i] ?? point.nums[point.nums.length - 1] ?? 1;
  });
  cats.forEach((p, i) => {
    const options = p.values.split(",").map((s) => s.trim()).filter(Boolean);
    const want = point.cats[i] ?? point.cats[0];
    row[p.name] = options.includes(want) ? want : options[Math.min(i, options.length - 1)] ?? want;
  });
  return row;
}

function take<T>(rows: T[], n: number): T[] {
  if (n <= 0) return rows.slice(0, 5);
  if (rows.length >= n) return rows.slice(0, n);
  const out = [...rows];
  while (out.length < n) out.push(rows[out.length % rows.length]);
  return out;
}

/** 演示模式专用结果：不调用 Python，产率落在 60–92% 的展示区间。 */
export function buildDemoShowcase(opts: {
  params: ParamRow[];
  isMulti: boolean;
  secondObjective: string;
  batchSize: number;
  round: number;
  engine: string;
}): Record<string, unknown> {
  const names = opts.params.map((p) => p.name).filter(Boolean);
  const round = Math.max(0, Math.min(2, opts.round)) as DemoRound;
  const batch = take(BATCHES[round], Number(opts.batchSize) || 5);
  const predictedNext = batch.map((p) => {
    const row = applyPoint(p, opts.params);
    row[`predicted_yield`] = p.predicted;
    if (opts.isMulti) row[opts.secondObjective] = p.cost;
    return row;
  });

  const historySrc = [...HISTORY, ...BATCHES.slice(0, round).flat()];
  const experiments = historySrc.map((p, i) => {
    const row = applyPoint(p, opts.params);
    row.yield = p.yield;
    row.batch = Math.min(5, Math.floor(i / 5) + 1);
    if (opts.isMulti) row[opts.secondObjective] = p.cost;
    return row;
  });

  const bestPoint = round === 0 ? HISTORY[HISTORY.length - 1] : BATCHES[round - 1][0];
  const best = {
    ...applyPoint(round === 2 ? BATCHES[2][0] : bestPoint, opts.params),
    yield: round === 0 ? 72.5 : round === 1 ? 81.2 : 91.4,
    ...(opts.isMulti ? { [opts.secondObjective]: round === 2 ? 37 : 38 } : {}),
  };

  const conv = [
    { step: 1, best_yield: 41.2, mean_yield: 28.6 },
    { step: 2, best_yield: 55.8, mean_yield: 39.4 },
    { step: 3, best_yield: 63.1, mean_yield: 48.7 },
    { step: 4, best_yield: 72.5, mean_yield: 57.2 },
    { step: 5, best_yield: round === 0 ? 72.5 : 81.2, mean_yield: 66.9 },
    { step: 6, best_yield: round >= 2 ? 91.4 : round === 1 ? 86.5 : 81.2, mean_yield: 72.1 },
  ];

  return {
    status: "success",
    mode: "showcase",
    engine: opts.engine,
    demo: true,
    target: "yield",
    parameter_names: names,
    batch_size: batch.length,
    iterations: 3,
    domain_size: 140,
    total_evaluations: experiments.length,
    predicted_next: predictedNext,
    recommended_experiments: predictedNext,
    experiments,
    best,
    convergence: conv,
    pareto_front: opts.isMulti
      ? BATCHES.flat()
          .slice(0, 5)
          .map((p) => ({
            ...applyPoint(p, opts.params),
            yield: p.yield,
            [opts.secondObjective]: p.cost,
          }))
      : [],
    objectives: opts.isMulti
      ? [
          { name: "yield", minimize: false },
          { name: opts.secondObjective, minimize: true },
        ]
      : [{ name: "yield", minimize: false }],
  };
}

export const DEMO_PROJECTS: ApiProject[] = [
  {
    id: "DEMO-024",
    task_uuid: "demo-suzuki",
    name: "Suzuki 偶联条件优化",
    engine: "AX",
    objective: "单目标 · 最大化产率",
    batches: 5,
    experiments: 30,
    best: "91.4 %",
    updated: "今天 09:12",
    status: "运行中",
    latest_uuid: "demo-suzuki",
    demo_mode: true,
  },
  {
    id: "DEMO-023",
    task_uuid: "demo-pareto",
    name: "光催化剂筛选（产率 / 成本）",
    engine: "AX",
    objective: "多目标 · Pareto",
    batches: 4,
    experiments: 24,
    best: "81.2 % / ¥38",
    updated: "昨天 17:40",
    status: "待录入",
    latest_uuid: "demo-pareto",
    demo_mode: true,
  },
  {
    id: "DEMO-021",
    task_uuid: "demo-mnl",
    name: "电解液添加剂组合",
    engine: "MNL",
    objective: "单目标 · 循环寿命",
    batches: 6,
    experiments: 36,
    best: "1420 cycles",
    updated: "8月21日",
    status: "已完成",
    latest_uuid: "demo-mnl",
    demo_mode: true,
  },
  {
    id: "DEMO-018",
    task_uuid: "demo-scaleup",
    name: "聚合工艺放大参数",
    engine: "AX",
    objective: "单目标 · 转化率",
    batches: 3,
    experiments: 18,
    best: "93.4 %",
    updated: "8月14日",
    status: "已完成",
    latest_uuid: "demo-scaleup",
    demo_mode: true,
  },
];

function remapTarget(row: Record<string, unknown>, from: string, to: string, map: (n: number) => number) {
  const next = { ...row };
  const raw = Number(next[from]);
  if (Number.isFinite(raw)) next[to] = map(raw);
  if (from !== to) delete next[from];
  return next;
}

function expandRows(rows: Record<string, unknown>[], n: number, target: string): Record<string, unknown>[] {
  if (rows.length === 0 || n <= 0) return rows;
  const out: Record<string, unknown>[] = [];
  for (let i = 0; i < n; i++) {
    const src = { ...rows[i % rows.length] };
    const y = Number(src[target]);
    if (Number.isFinite(y)) {
      const wobble = ((i * 17) % 9) - 4;
      src[target] = Number((y + wobble * 0.35).toFixed(1));
    }
    src.batch = Math.floor(i / 5) + 1;
    out.push(src);
  }
  return out;
}

function pickBest(rows: Record<string, unknown>[], target: string): Record<string, unknown> | null {
  let best: Record<string, unknown> | null = null;
  let score = -Infinity;
  for (const row of rows) {
    const y = Number(row[target]);
    if (Number.isFinite(y) && y > score) {
      score = y;
      best = row;
    }
  }
  return best;
}

/** 课题页 / 分析页用的演示运行结果：含最优组合与全部实验记录。 */
export function buildDemoProjectResult(uuid?: string): Record<string, unknown> {
  const project = DEMO_PROJECTS.find((p) => p.latest_uuid === uuid || p.task_uuid === uuid) ?? DEMO_PROJECTS[0];
  const isMulti = project.latest_uuid === "demo-pareto";
  const raw = buildDemoShowcase({
    params: defaultParams,
    isMulti,
    secondObjective: "cost",
    batchSize: 5,
    round: 2,
    engine: project.engine,
  });

  let target = "yield";
  let experiments = (Array.isArray(raw.experiments) ? raw.experiments : []) as Record<string, unknown>[];
  experiments = expandRows(experiments, project.experiments, "yield");

  if (project.latest_uuid === "demo-mnl") {
    target = "cycles";
    experiments = experiments.map((e) => remapTarget(e, "yield", "cycles", (y) => Math.round(800 + (y - 40) * 14)));
  } else if (project.latest_uuid === "demo-scaleup") {
    target = "conversion";
    experiments = experiments.map((e) => remapTarget(e, "yield", "conversion", (y) => Number((y + 2.2).toFixed(1))));
  }

  const best = pickBest(experiments, target) ?? (raw.best as Record<string, unknown>);
  if (best && target !== "yield" && best.yield != null && best[target] == null) {
    const y = Number(best.yield);
    if (target === "cycles") best.cycles = Math.round(800 + (y - 40) * 14);
    if (target === "conversion") best.conversion = Number((y + 2.2).toFixed(1));
    delete best.yield;
  }

  return {
    ...raw,
    target,
    experiments,
    best,
    project_name: project.name,
    total_evaluations: experiments.length,
    domain_size: 140,
    iterations: 3,
    batch_size: 5,
  };
}

export function demoRunConfig() {
  return {
    target: "yield",
    batch_size: 5,
    iterations: 3,
    prior_results: [] as Record<string, unknown>[],
    parameters: defaultParams.map((p) => ({
      name: p.name,
      encoding: p.encoding,
      values:
        p.encoding === "numeric"
          ? p.values.split(",").map((s) => Number(s.trim()))
          : p.values.split(",").map((s) => s.trim()).filter(Boolean),
    })),
  };
}
