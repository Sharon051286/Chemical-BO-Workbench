import { createFileRoute, Link } from "@tanstack/react-router";
import { ArrowUpRight, Plus } from "lucide-react";
import { useEffect, useState } from "react";

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { fetchProjects, type ApiProject } from "@/lib/api";
import { useAppMode } from "@/lib/app-mode";
import { DEMO_PROJECTS } from "@/lib/demo-showcase";

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
} as const;

function Projects() {
  const { demoMode } = useAppMode();
  const [rows, setRows] = useState<ApiProject[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

  return (
    <div className="mx-auto w-full max-w-[1500px] px-4 py-6 lg:px-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">我的课题</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {demoMode
              ? "当前为演示工作区：下列为样例课题，不会写入后端。"
              : "同一课题的多次「保存并重新推荐」按 task_uuid 聚合。只列出正式模式运行。"}
          </p>
        </div>
        <Button size="sm" asChild>
          <Link to="/">
            <Plus className="size-4" /> 新建课题
          </Link>
        </Button>
      </div>

      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "课题总数", value: loaded ? String(rows.length) : "—" },
          { label: "累计批次", value: loaded ? String(rows.reduce((s, p) => s + (p.batches || 0), 0)) : "—" },
          { label: "累计实验", value: loaded ? String(rows.reduce((s, p) => s + (p.experiments || 0), 0)) : "—" },
          { label: "数据来源", value: demoMode ? "演示样例" : error ? "失败" : "后端 API" },
        ].map((s) => (
          <div key={s.label} className="panel p-4">
            <p className="text-xs text-muted-foreground">{s.label}</p>
            <p className="num mt-1.5 text-2xl font-semibold">{s.value}</p>
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
                  <TableCell>
                    <Link to="/analysis" search={{ uuid: p.latest_uuid }} aria-label={`打开 ${p.name}`}>
                      <ArrowUpRight className="size-4 text-muted-foreground" />
                    </Link>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
