@props(['title' => 'EDBO 工作台'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="EDBO 贝叶斯优化 Web 工作台 — 让化学实验团队无需编写代码即可运行实验设计优化">
    <title>{{ $title ?? 'EDBO 工作台' }}</title>

    {{-- Flux 外观指令：自动处理明暗主题与系统偏好 --}}
    @fluxAppearance

    {{-- Inter 字体（可选，缺失时优雅降级到系统字体） --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

    {{-- Vite 资源（Tailwind v4 + Flux CSS） --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-gradient-to-br from-slate-50 via-white to-edbo-50 text-slate-800 antialiased
             dark:from-slate-950 dark:via-slate-900 dark:to-edbo-950 dark:text-slate-100">

    {{-- 单一根包裹：满足 Livewire 全页组件「<body> 仅一个直接子元素」约束 --}}
    <div class="flex min-h-screen flex-col">

        {{-- 顶部导航：玻璃拟态 --}}
        <header class="sticky top-0 z-50 border-b border-white/40 bg-white/60 backdrop-blur-xl
                       dark:border-white/10 dark:bg-slate-900/60">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
                <a href="{{ route('edbo.index') }}" class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-edbo-500 to-edbo-700 text-white shadow-lg shadow-edbo-500/30">
                        <flux:icon name="beaker" class="h-5 w-5" />
                    </span>
                    <span class="text-lg font-semibold tracking-tight">EDBO 工作台</span>
                </a>

                <nav class="flex items-center gap-1 text-sm font-medium">
                    <flux:link href="{{ route('edbo.index') }}" wire:navigate class="rounded-lg px-3 py-1.5 hover:bg-edbo-50 dark:hover:bg-white/10">
                        优化工作台
                    </flux:link>
                    <flux:link href="{{ route('edbo.docs') }}" wire:navigate class="rounded-lg px-3 py-1.5 hover:bg-edbo-50 dark:hover:bg-white/10">
                        使用指南
                    </flux:link>
                    <flux:button href="https://github.com/b-shields/edbo" target="_blank" variant="primary" size="sm" class="ml-2">
                        关于 EDBO
                    </flux:button>
                </nav>
            </div>
        </header>

        {{-- 主内容区 --}}
        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 sm:py-10">
            {{ $slot }}
        </main>

        {{-- 页脚 --}}
        <footer class="mx-auto max-w-7xl px-4 py-6 text-center text-xs text-slate-400 sm:px-6">
            基于 <a href="https://github.com/b-shields/edbo" target="_blank" class="underline hover:text-edbo-600">EDBO</a>
            (Experimental Design via Bayesian Optimization) 构建 · Sharon出品
        </footer>
    </div>

    {{-- Livewire + Flux 脚本 --}}
    @livewireScripts
    @fluxScripts
</body>
</html>
