export type Encoding = "numeric" | "ohe" | "resolve";

export type ParamRow = {
  id: string;
  name: string;
  encoding: Encoding;
  values: string;
  unit?: string;
};

export const encodingLabel: Record<Encoding, string> = {
  numeric: "numeric · 连续数值",
  ohe: "ohe · 类别 One-Hot",
  resolve: "resolve · 化学结构式",
};

export const encodingShort: Record<Encoding, string> = {
  numeric: "numeric",
  ohe: "ohe",
  resolve: "resolve",
};

export const defaultParams: ParamRow[] = [
  { id: "p1", name: "温度(℃)", encoding: "numeric", values: "80, 160", unit: "°C" },
  { id: "p2", name: "时间(h)", encoding: "numeric", values: "1, 16", unit: "h" },
  { id: "p3", name: "催化剂(mol%)", encoding: "numeric", values: "1, 10", unit: "mol%" },
  { id: "p4", name: "碱", encoding: "ohe", values: "K2CO3, Cs2CO3, KOtBu, Et3N" },
  {
    id: "p5",
    name: "溶剂(SMILES)",
    encoding: "resolve",
    values:
      "O,CO,CCO,CCCO,CC(C)O,CCCCO,CC(C)CO,CC(C)(C)O,CCCCCCO,OC1CCCCC1,OCCO,OCC(O)CO,Oc1ccccc1,OCC1=CC=CC=C1,CC(=O)C,CC#N,CCC#N,CN(C)C=O,CC(=O)N(C)C,O=C1CCCN1,CS(C)=O,O=S1(=O)CCCC1,CC(=O)O,C1CCOC1,C1COCCO1,COCCOC,Cc1ccccc1,c1ccccc1,Clc1ccccc1,ClCCl,CCCCCC,C1CCCCC1,CCOCC,c1ccncc1,C1COCCN1",
  },
];

export const acquisitions: Record<string, string> = {
  EI: "EI (期望改进)",
  TS: "TS (Thompson 采样)",
  PI: "PI (改进概率)",
  UCB: "UCB (置信上界)",
  "EI-TS": "EI + TS 混合",
};

export const initMethods: Record<string, string> = {
  rand: "随机 (rand)",
  kmedoids: "k-medoids 聚类",
  kmeans: "k-means 聚类",
};

export type Suggestion = {
  id: number;
  temp: number;
  cat: number;
  time: number;
  base: string;
  solvent: string;
  predicted: number;
  ei: number;
  observed?: number;
};

export const suggestions: Suggestion[] = [
  { id: 1, temp: 96, cat: 2.4, time: 12, base: "Cs2CO3", solvent: "MeCN", predicted: 78.4, ei: 0.142, observed: 81.2 },
  { id: 2, temp: 84, cat: 3.1, time: 18, base: "K2CO3", solvent: "DMSO", predicted: 74.9, ei: 0.128, observed: 72.5 },
  { id: 3, temp: 112, cat: 1.2, time: 8, base: "KOtBu", solvent: "THF", predicted: 71.2, ei: 0.119 },
  { id: 4, temp: 62, cat: 4.4, time: 22, base: "Cs2CO3", solvent: "MeOH", predicted: 66.8, ei: 0.104 },
  { id: 5, temp: 104, cat: 0.8, time: 15, base: "Et3N", solvent: "MeCN", predicted: 63.5, ei: 0.097 },
];

export const convergence = [
  { batch: "B1", best: 41.2, mean: 28.6 },
  { batch: "B2", best: 55.8, mean: 39.4 },
  { batch: "B3", best: 63.1, mean: 48.7 },
  { batch: "B4", best: 72.5, mean: 57.2 },
  { batch: "B5", best: 81.2, mean: 66.9 },
];

export const importance = [
  { name: "反应温度", value: 34 },
  { name: "溶剂", value: 26 },
  { name: "催化剂当量", value: 19 },
  { name: "碱", value: 13 },
  { name: "反应时间", value: 8 },
];

export const paretoFront = [
  { yield: 42, cost: 12, batch: "B1" },
  { yield: 55, cost: 21, batch: "B2" },
  { yield: 63, cost: 26, batch: "B3" },
  { yield: 72, cost: 38, batch: "B4" },
  { yield: 81, cost: 54, batch: "B5" },
];

export const paretoDominated = [
  { yield: 31, cost: 24 },
  { yield: 38, cost: 41 },
  { yield: 47, cost: 49 },
  { yield: 52, cost: 58 },
  { yield: 60, cost: 62 },
  { yield: 66, cost: 71 },
];

export const slice = Array.from({ length: 25 }, (_, i) => {
  const t = 40 + i * (80 / 24);
  return {
    temp: Math.round(t),
    mean: Number((30 + 55 * Math.exp(-((t - 98) ** 2) / 900)).toFixed(1)),
    upper: Number((38 + 58 * Math.exp(-((t - 98) ** 2) / 900)).toFixed(1)),
    lower: Number((22 + 50 * Math.exp(-((t - 98) ** 2) / 900)).toFixed(1)),
  };
});

export type Project = {
  id: string;
  name: string;
  engine: "Ax" | "MNL";
  objective: string;
  batches: number;
  experiments: number;
  best: string;
  updated: string;
  status: "运行中" | "待录入" | "已完成";
};

export const projects: Project[] = [
  {
    id: "EDBO-024",
    name: "Suzuki 偶联条件优化",
    engine: "Ax",
    objective: "单目标 · 最大化产率",
    batches: 5,
    experiments: 30,
    best: "81.2 %",
    updated: "今天 09:12",
    status: "运行中",
  },
  {
    id: "EDBO-023",
    name: "光催化剂筛选（产率 / 成本）",
    engine: "Ax",
    objective: "多目标 · Pareto",
    batches: 4,
    experiments: 24,
    best: "72.5 % / ¥38",
    updated: "昨天 17:40",
    status: "待录入",
  },
  {
    id: "EDBO-021",
    name: "电解液添加剂组合",
    engine: "MNL",
    objective: "单目标 · 循环寿命",
    batches: 6,
    experiments: 36,
    best: "1420 cycles",
    updated: "8月21日",
    status: "已完成",
  },
  {
    id: "EDBO-018",
    name: "聚合工艺放大参数",
    engine: "Ax",
    objective: "单目标 · 转化率",
    batches: 3,
    experiments: 18,
    best: "93.4 %",
    updated: "8月14日",
    status: "已完成",
  },
];
