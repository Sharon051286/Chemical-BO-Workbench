import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowUpRight, Plus, Trash2 } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { deleteProject, fetchProjects, type ApiProject } from "@/lib/api";
import { InfoHint } from "@/components/info-hint";
import { useAppMode } from "@/lib/app-mode";
import { DEMO_PROJECTS } from "@/lib/demo-showcase";
import { persistFormalSession, readFormalSession } from "@/lib/run-hydrate";

export const Route = createFileRoute("/projects")({
  head: () => ({
    meta: [
      { title: "我的课题 · EDBO Web" },
      {
        name: "description",
        content: "查看所有贝叶斯优化课题的批次进度、引擎类型与当前最优结果。",
      },
      { property: "og:title", content: "我的课题 · EDBO Web" },
      { property: "og:description", content: "课题批次进度与当前最优结果一览。" },
    ],
  }),
  component: Projects,
});

const statusVariant = {
  运行中: "default",
  待录入: "secondary",
  已完成: "outline",
  失败: "destructive",
} as const;

function Projects() {
  const { demoMode } = useAppMode();
  const [rows, setRows] = useState<ApiProject[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [removing, setRemoving] = useState<ApiProject | null>(null);

  useEffect(() => {
    if (demoMode) {
      setRows(DEMO_PROJECTS);
      setLoaded(true);
      setError(null);
      return;
    }
    setLoaded(false);
    void fetchProjects()
      .then((list) => {
        setRows(list);
        setLoaded(true);
      })
      .catch((e) => {
        setError(e instanceof Error ? e.message : "无法读取课题列表");
        setLoaded(true);
      });
  }, [demoMode]);

  const pending = rows.filter((p) => p.status === "待录入");
  const running = rows.filter((p) => p.status === "运行中");
  const done = rows.filter((p) => p.status === "已完成");
  const latest = rows[0];
  const measuredBests = rows
    .map((p) => {
      const m = String(p.best ?? "").match(/-?\d+(?:\.\d+)?/);
      if (!m || /[—\-]$/.test(String(p.best).trim()) || p.best === "—") return null;
      return { project: p, value: Number(m[0]) };
    })
    .filter((x): x is { project: ApiProject; value: number } => x != null && Number.isFinite(x.value));
  const top = measuredBests.sort((a, b) => b.value - a.value)[0];

  const summaries = loaded
    ? [
        {
          label: "待录入",
          value: String(pending.length),
          numeric: true,
          hint: pending.length > 0 ? "这些课题已有推荐，等实测回填" : "没有等待回填的推荐",
        },
        {
          label: "运行中",
          value: String(running.length),
          numeric: true,
          hint: running.length > 0 ? "优化还在出下一批条件" : "当前没有排队中的优化",
        },
        {
          label: "已完成",
          value: String(done.length),
          numeric: true,
          hint: `${rows.length} 个课题中已结束迭代的数量`,
        },
        top
          ? {
              label: "已测到的最高目标",
              value: top.project.best,
              numeric: true,
              hint: top.project.name,
            }
          : {
              label: "已测到的最高目标",
              value: "尚无",
              numeric: false,
              hint: latest ? `最近更新：${latest.name}` : "还没有带实测值的课题",
            },
      ]
    : [
        { label: "待录入", value: "—", numeric: false, hint: "" },
        { label: "运行中", value: "—", numeric: false, hint: "" },
        { label: "已完成", value: "—", numeric: false, hint: "" },
        { label: "已测到的最高目标", value: "—", numeric: false, hint: "" },
      ];

  return (
    <div className="mx-auto w-full max-w-[1500px] px-4 py-6 lg:px-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-2xl font-semibold">我的课题</h1>
            <InfoHint label="课题列表说明">
              {demoMode
                ? "当前为演示工作区：下列为样例课题，不会写入后端。"
                : "同一课题的多次「保存并重新推荐」按 task_uuid 聚合，只列出正式模式运行。"}
            </InfoHint>
          </div>
        </div>
        <Button size="sm" asChild>
          <Link to="/">
            <Plus className="size-4" /> 新建课题
          </Link>
        </Button>
      </div>

      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {summaries.map((s) => (
          <div key={s.label} className="panel p-4">
            <p className="text-xs text-muted-foreground">{s.label}</p>
            <p className="mt-1.5 text-2xl font-semibold">
              <span className={s.numeric ? "num" : ""}>{s.value}</span>
            </p>
            {s.hint ? <p className="mt-1 text-xs text-muted-foreground">{s.hint}</p> : null}
          </div>
        ))}
      </div>

      {error && !demoMode && <p className="mt-4 text-sm text-destructive">{error}</p>}

      <div className="panel mt-6 overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow className="bg-surface/70">
              <TableHead>课题</TableHead>
              <TableHead>引擎</TableHead>
              <TableHead>目标</TableHead>
              <TableHead className="text-right">批次</TableHead>
              <TableHead className="text-right">实验数</TableHead>
              <TableHead>当前最优</TableHead>
              <TableHead>更新</TableHead>
              <TableHead>状态</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {!loaded ? (
              <TableRow>
                <TableCell colSpan={9} className="py-8 text-center text-sm text-muted-foreground">
                  正在加载…
                </TableCell>
              </TableRow>
            ) : rows.length === 0 ? (
              <TableRow>
                <TableCell colSpan={9} className="py-8 text-center text-sm text-muted-foreground">
                  还没有课题。到工作台用「正式」模式跑一轮后会出现在这里。
                </TableCell>
              </TableRow>
            ) : (
              rows.map((p) => (
                <TableRow key={p.task_uuid || p.id}>
                  <TableCell>
                    <p className="font-medium">{p.name}</p>
                    <p className="num text-xs text-muted-foreground">{p.id}</p>
                  </TableCell>
                  <TableCell>
                    <Badge variant="outline">{p.engine}</Badge>
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">{p.objective}</TableCell>
                  <TableCell className="num text-right">{p.batches}</TableCell>
                  <TableCell className="num text-right">{p.experiments}</TableCell>
                  <TableCell className="num font-medium">{p.best}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">{p.updated}</TableCell>
                  <TableCell>
                    <Badge variant={statusVariant[p.status] ?? "outline"}>{p.status}</Badge>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <div className="flex items-center gap-2">
                      <Link
                        to="/"
                        search={{ task: p.task_uuid }}
                        className="text-xs text-primary hover:underline"
                      >
                        继续优化
                      </Link>
                      <Link
                        to="/analysis"
                        search={{ uuid: p.latest_uuid }}
                        className="inline-flex items-center gap-0.5 text-xs text-muted-foreground hover:underline"
                      >
                        {p.status === "待录入" ? "录入结果" : "查看"}
                        <ArrowUpRight className="size-3.5" />
                      </Link>
                      {!demoMode && (
                        <button
                          type="button"
                          className="text-muted-foreground hover:text-destructive"
                          aria-label={`删除 ${p.name}`}
                          onClick={() => setRemoving(p)}
                        >
                          <Trash2 className="size-3.5" />
                        </button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>

      <AlertDialog open={Boolean(removing)} onOpenChange={(on) => !on && setRemoving(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>删除这个课题？</AlertDialogTitle>
            <AlertDialogDescription>
              将删除「{removing?.name}」及其全部运行记录和结果文件，无法恢复。
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>取消</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (!removing) return;
                const id = removing.task_uuid;
                void deleteProject(id)
                  .then(() => {
                    setRows((list) => list.filter((p) => p.task_uuid !== id));
                    if (readFormalSession()?.taskUuid === id) persistFormalSession(null, "");
                    toast.success("课题已删除");
                  })
                  .catch((e) => toast.error(e instanceof Error ? e.message : "删除失败"))
                  .finally(() => setRemoving(null));
              }}
            >
              删除
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
