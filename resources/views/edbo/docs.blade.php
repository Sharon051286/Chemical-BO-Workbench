<x-layouts.app title="使用指南 · EDBO 工作台">
    <div class="mx-auto max-w-3xl space-y-8">

        <div>
            <flux:heading size="xl" class="font-semibold tracking-tight">使用指南与架构说明</flux:heading>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">面向团队的技术交接文档：如何用、为何这样设计、以及如何接入真实实验数据。</p>
        </div>

        {{-- 快速上手 --}}
        <flux:card class="space-y-3">
            <flux:heading size="lg">一、快速上手</flux:heading>
            <ol class="list-decimal space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li>在「实验空间定义」中为每个参数填写<strong>名称</strong>与<strong>取值</strong>（英文逗号分隔，如 <code class="rounded bg-slate-100 px-1 dark:bg-white/10">80,100,120,140,160</code>）。</li>
                <li>数值会自动识别为连续/离散变量；文字（如 <code class="rounded bg-slate-100 px-1 dark:bg-white/10">K2CO3,Cs2CO3</code>）会被当作类别变量做独热编码。</li>
                <li>设置批量大小、迭代轮数、采集函数与初始化方法。</li>
                <li>点击「运行贝叶斯优化」，等待高斯过程训练完成，右侧即展示最优条件、收敛曲线与推荐实验。</li>
            </ol>
            <flux:callout icon="information-circle">
                提示：当前为<strong>模拟模式</strong>，使用合成目标函数展示 EDBO 的寻优能力，便于无实验数据时也能体验完整流程。
            </flux:callout>
        </flux:card>

        {{-- 架构 --}}
        <flux:card class="space-y-3">
            <flux:heading size="lg">二、系统架构（资深开发视角）</flux:heading>
            <p class="text-sm text-slate-600 dark:text-slate-300">整套系统由两个相互隔离的运行时构成，通过文件系统（JSON）解耦，避免语言运行时之间的脆弱管道：</p>
            <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-white/10">
                <table class="w-full text-left text-sm">
                    <tbody class="divide-y divide-slate-100 dark:divide-white/5">
                        <tr><td class="px-3 py-2 font-medium">前端 / 应用层</td><td class="px-3 py-2 text-slate-600 dark:text-slate-300">Laravel + Livewire + FluxUI（PHP），承载表单、校验、结果渲染</td></tr>
                        <tr><td class="px-3 py-2 font-medium">桥接服务</td><td class="px-3 py-2 text-slate-600 dark:text-slate-300"><code class="text-xs">App\Services\EdboService</code>：用 Process Facade 调用 micromamba，超时 600s，错误结构化返回</td></tr>
                        <tr><td class="px-3 py-2 font-medium">优化引擎</td><td class="px-3 py-2 text-slate-600 dark:text-slate-300"><code class="text-xs">scripts/edbo_runner.py</code>：在 Python 3.7.5 环境中调用 EDBO，结果写文件</td></tr>
                        <tr><td class="px-3 py-2 font-medium">数据交换</td><td class="px-3 py-2 text-slate-600 dark:text-slate-300">请求/响应均为 storage/app/edbo/runs/ 下的 JSON 文件</td></tr>
                    </tbody>
                </table>
            </div>
            <flux:callout icon="light-bulb">
                <strong>设计取舍：</strong>为何用文件而非 stdout 管道传递结果？因为 EDBO 内部会向 stdout 打印日志（edbo bot），直接解析 stdout 极易被污染。写文件后由 PHP 侧读取，隔离更干净、可调试。
            </flux:callout>
        </flux:card>

        {{-- 接入真实数据 --}}
        <flux:card class="space-y-3">
            <flux:heading size="lg">三、接入真实实验数据</flux:heading>
            <p class="text-sm text-slate-600 dark:text-slate-300">模拟模式使用合成目标函数。要用于真实化学优化，只需修改 <code class="text-xs">scripts/edbo_runner.py</code> 中的目标函数：</p>
            <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100"><code>def objective(row):
    # 不再用合成函数，改为：
    # 1) 查数据库/Excel 中该参数组合的实测产率，或
    # 2) 改为 human-in-loop：把实验结果回写 exindex 后调用 bo.run()
    return lookup_yield_from_lab(row)</code></pre>
            <p class="text-sm text-slate-600 dark:text-slate-300">推荐路径：将 <code class="text-xs">bo.simulate()</code> 替换为「<code class="text-xs">init_sample()</code> → 录入真实产率 → <code class="text-xs">run(append=True)</code>」的循环，即可实现真正的人机协同优化。</p>
        </flux:card>

        {{-- 代码规范 --}}
        <flux:card class="space-y-3">
            <flux:heading size="lg">四、团队可借鉴的代码规范</flux:heading>
            <ul class="list-disc space-y-2 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li><strong>职责下沉：</strong>路由只做转发，业务逻辑在 Livewire 组件与 Service，视图只负责呈现。</li>
                <li><strong>校验前置：</strong>外部调用前在 PHP 侧完成参数解析与组合爆炸防护（&gt;20 万组合拦截）。</li>
                <li><strong>错误边界：</strong>Python 侧任何异常都写出结构化错误 JSON，PHP 侧再转译为友好提示。</li>
                <li><strong>零依赖克制：</strong>收敛曲线用内联 SVG 绘制，避免为一张图引入重型图表库。</li>
                <li><strong>明暗主题：</strong>通过 Flux 的 <code class="text-xs">@fluxAppearance</code> 自动适配系统主题，无需手动切换。</li>
            </ul>
        </flux:card>

        <div class="text-center">
            <flux:button href="{{ route('edbo.index') }}" variant="primary" wire:navigate>← 返回工作台</flux:button>
        </div>
    </div>
</x-layouts.app>
