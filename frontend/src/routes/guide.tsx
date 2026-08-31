import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowLeft, Binary, FlaskConical, AlertTriangle } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";

export const Route = createFileRoute("/guide")({
  head: () => ({
    meta: [
      { title: "使用说明 · EDBO Web" },
      {
        name: "description",
        content: "了解参数编码格式、Ax / MNL 双引擎差异以及域规模守卫规则。",
      },
      { property: "og:title", content: "使用说明 · EDBO Web" },
      { property: "og:description", content: "参数编码、双引擎差异与域规模守卫。" },
      { property: "og:type", content: "website" },
      { name: "twitter:card", content: "summary_large_image" },
    ],
  }),
  component: Guide,
});

const Code = ({ children }: { children: React.ReactNode }) => (
  <code className="rounded bg-surface/60 px-1.5 py-0.5 text-xs font-mono text-foreground">{children}</code>
);

function Guide() {
  return (
    <div className="mx-auto w-full max-w-[1100px] px-4 py-6 lg:px-8">
      <div className="flex items-center gap-3">
        <Button variant="outline" size="sm" asChild>
          <Link to="/">
            <ArrowLeft className="size-4" /> 返回工作台
          </Link>
        </Button>
      </div>

      <div className="mt-6">
        <h1 className="text-2xl font-semibold">使用说明</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          EDBO Web 的输入约定、优化引擎差异与推荐组合可行性检查。
        </p>
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <section className="panel p-5">
          <div className="flex items-center gap-2">
            <Binary className="size-5 text-primary" />
            <h2 className="text-base font-semibold">参数编码</h2>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            根据变量类型选择编码，模型才能正确理解参数空间。
          </p>

          <div className="mt-4 space-y-4">
            <div>
              <div className="flex items-center gap-2">
                <Badge variant="secondary">numeric</Badge>
                <span className="text-sm font-medium">连续数值</span>
              </div>
              <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                用于温度、浓度、时间等可连续变化的量。在「取值范围」中填写 <Code>下限, 上限</Code>，例如 <Code>40, 120</Code>。
              </p>
            </div>

            <div>
              <div className="flex items-center gap-2">
                <Badge variant="secondary">ohe</Badge>
                <span className="text-sm font-medium">类别 One-Hot</span>
              </div>
              <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                用于离散选项，如碱、配体、催化剂类型。候选值用逗号分隔，例如 <Code>K2CO3, Cs2CO3, KOtBu, Et3N</Code>。
              </p>
            </div>

            <div>
              <div className="flex items-center gap-2">
                <Badge variant="secondary">resolve</Badge>
                <span className="text-sm font-medium">化学结构式</span>
              </div>
              <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                用于溶剂、单体等可通过化学结构区分的候选。支持 SMILES 或化合物名，例如 <Code>CC#N, CO, C1CCOC1, CS(C)=O</Code>。
              </p>
            </div>
          </div>
        </section>

        <section className="panel p-5">
          <div className="flex items-center gap-2">
            <FlaskConical className="size-5 text-primary" />
            <h2 className="text-base font-semibold">双引擎差异</h2>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            根据实验目标与数据量选择 Ax / BoTorch 或 MNL 引擎。
          </p>

          <div className="mt-4 space-y-4">
            <div>
              <div className="flex items-center gap-2">
                <Badge>Ax / BoTorch</Badge>
              </div>
              <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                基于高斯过程（GP）的贝叶斯优化，适合连续-离散混合参数空间。可同时支持单目标和多目标 Pareto 优化，推荐策略更充分。
              </p>
            </div>

            <div>
              <div className="flex items-center gap-2">
                <Badge variant="outline">MNL</Badge>
              </div>
              <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                多项 Logit 离散选择模型，更适合纯离散/类别组合筛选。运行速度快，对早期数据量要求低，但不处理连续变量。
              </p>
            </div>

            <div className="rounded-md border border-border bg-accent/40 p-3 text-xs leading-relaxed text-accent-foreground">
              <p>
                建议：连续/混合变量且预算充足选 Ax；只做离散类别快速筛选选 MNL。
              </p>
            </div>
          </div>
        </section>

        <section className="panel p-5">
          <div className="flex items-center gap-2">
            <AlertTriangle className="size-5 text-warning" />
            <h2 className="text-base font-semibold">域规模守卫</h2>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">
            避免在无法提供足够不重复组合的参数空间上运行优化。
          </p>

          <div className="mt-4 space-y-4">
            <p className="text-xs leading-relaxed text-muted-foreground">
              当参数空间中存在 <Code>ohe</Code> 或 <Code>resolve</Code> 等离散编码时，系统会计算离散组合数：
            </p>

            <div className="rounded-md border border-border bg-surface/60 p-3 text-xs font-mono leading-relaxed">
              组合数 = 各离散参数候选值个数的乘积
            </div>

            <p className="text-xs leading-relaxed text-muted-foreground">
              总评估预算（批量 ×（迭代 + 1）+ 先验条数）必须不超过该组合数。否则平台会在工作台给出警告，无法提交运行。
            </p>

            <div className="rounded-md border border-destructive/35 bg-destructive/10 p-3 text-xs font-medium leading-relaxed text-destructive">
              <p>
                如果组合数接近预算，推荐结果可能会出现重复组合；建议减少候选取值或增加批量大小以加速覆盖空间。
              </p>
            </div>
          </div>
        </section>
      </div>

      <section className="panel mt-6 p-5">
        <h2 className="text-base font-semibold">演示模式与正式模式</h2>
        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
          工作台右上角开关把两条链路隔开：演示模式下工作台、课题列表与分析视图全部使用样例数据；正式模式才读写后端运行。切换时两边各自保留，不会互相覆盖。
        </p>
      </section>

      <section className="panel mt-6 p-5">
        <h2 className="text-base font-semibold">完整 Livewire 工作台</h2>
        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
          先验数据助手（RAG / 启发式建议）与原版说明仍在 Laravel 页面：
          <a className="mx-1 text-foreground underline" href="http://127.0.0.1:8000" target="_blank" rel="noreferrer">
            http://127.0.0.1:8000
          </a>
          （含先验助手）与
          <a className="mx-1 text-foreground underline" href="http://127.0.0.1:8000/docs" target="_blank" rel="noreferrer">
            /docs
          </a>
          。本 React 界面已接通同一套 Ax/MNL 引擎与课题累积逻辑。
        </p>
      </section>

      <section className="panel mt-6 p-5">
        <h2 className="text-base font-semibold">快速检查清单</h2>
        <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
          {[
            "每个参数都已选择正确编码",
            "numeric 参数使用「下限, 上限」格式",
            "ohe / resolve 候选值用逗号分隔，无多余空格",
            "批量与轮数相乘后的预算合理",
            "离散组合数大于等于总评估预算",
            "目标函数与引擎选择匹配",
          ].map((item) => (
            <label key={item} className="flex items-start gap-2">
              <input type="checkbox" className="mt-1 size-4 rounded border-border" />
              <span className="text-muted-foreground">{item}</span>
            </label>
          ))}
        </div>
      </section>
    </div>
  );
}
