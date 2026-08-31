import { Link, useRouterState } from "@tanstack/react-router";
import { FlaskConical, LayoutGrid, LineChart, BookOpen, ExternalLink } from "lucide-react";
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
        <SidebarGroup>
          <SidebarGroupLabel>原 Livewire 工作台</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton asChild tooltip="完整优化器与先验助手">
                  <a href="http://127.0.0.1:8000" target="_blank" rel="noreferrer" className="flex items-center gap-2">
                    <ExternalLink className="size-4" />
                    {!collapsed && <span>先验助手 / 全功能页</span>}
                  </a>
                </SidebarMenuButton>
              </SidebarMenuItem>
              <SidebarMenuItem>
                <SidebarMenuButton asChild tooltip="原使用指南">
                  <a href="http://127.0.0.1:8000/docs" target="_blank" rel="noreferrer" className="flex items-center gap-2">
                    <BookOpen className="size-4" />
                    {!collapsed && <span>原使用说明</span>}
                  </a>
                </SidebarMenuButton>
              </SidebarMenuItem>
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
