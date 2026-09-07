import { Link, useRouterState } from "@tanstack/react-router";
import { FlaskConical, LayoutGrid, LineChart, BookOpen } from "lucide-react";
import { useEffect, useState } from "react";

import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from "@/components/ui/sidebar";
import { fetchHealth } from "@/lib/api";
import { useAppMode } from "@/lib/app-mode";

const items = [
  { title: "优化工作台", url: "/", icon: FlaskConical },
  { title: "我的课题", url: "/projects", icon: LayoutGrid },
  { title: "分析视图", url: "/analysis", icon: LineChart },
  { title: "使用说明", url: "/guide", icon: BookOpen },
];

export function AppSidebar() {
  const { state } = useSidebar();
  const collapsed = state === "collapsed";
  const currentPath = useRouterState({ select: (r) => r.location.pathname });
  const { demoMode } = useAppMode();
  const [healthLabel, setHealthLabel] = useState("正在检测 Python 内核…");

  useEffect(() => {
    void fetchHealth()
      .then((h) => {
        setHealthLabel(
          h.ready
            ? `就绪 · Ax ${h.ax_importable ? "已加载" : "未装"} / MNL ${h.mnl_importable ? "已加载" : "未装"}`
            : "未就绪 · 请检查 edbo-ax 环境",
        );
      })
      .catch(() => setHealthLabel("无法连接 Laravel API（:8000）"));
  }, []);

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader className="border-b border-sidebar-border">
        <div className="flex items-center gap-2.5 px-1.5 py-2">
          <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground">
            <FlaskConical className="size-4" />
          </div>
          {!collapsed && (
            <div className="min-w-0">
              <p className="truncate font-display text-sm font-semibold leading-tight">EDBO Web</p>
              <p className="truncate text-xs text-muted-foreground">
                {demoMode ? "演示工作区 · 样例数据" : "实验设计贝叶斯优化"}
              </p>
            </div>
          )}
        </div>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>工作区</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {items.map((item) => (
                <SidebarMenuItem key={item.url}>
                  <SidebarMenuButton asChild isActive={currentPath === item.url} tooltip={item.title}>
                    <Link to={item.url} className="flex items-center gap-2">
                      <item.icon className="size-4" />
                      {!collapsed && <span>{item.title}</span>}
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>

      {!collapsed && (
        <SidebarFooter className="border-t border-sidebar-border">
          <div className="rounded-md bg-sidebar-accent/60 p-3 text-xs leading-relaxed text-sidebar-accent-foreground">
            <p className="font-medium">Python 内核</p>
            <p className="num mt-1 text-[11px] opacity-80">{healthLabel}</p>
          </div>
        </SidebarFooter>
      )}
    </Sidebar>
  );
}
