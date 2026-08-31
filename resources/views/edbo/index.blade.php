<?php
/**
 * 工作台主视图
 * ----------
 * 布局分层：顶部健康状况条 → 顶部菜单栏 → 按菜单切换的内容区。
 * 收敛曲线用内联 SVG 绘制（零依赖），既轻量又体现「不滥用重型图表库」的克制。
 *
 * 布局由组件 render()->layout() 注入，本文件仅承载内容区（单一根节点 <div>），
 * 从而规避全页组件「Multiple root elements」错误。
 */
?>
<div class="space-y-8">

        {{-- 标题区 --}}
        <div>
            <flux:heading size="xl" class="font-semibold tracking-tight">贝叶斯实验优化工作台</flux:heading>
            <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                定义你的实验参数空间，点击运行，EDBO 会在数秒内给出最优条件与下一批推荐实验。
                底层由 Python 端的三套贝叶斯优化引擎驱动：<span class="font-medium text-edbo-600">EDBO</span>（高斯过程）、<span class="font-medium text-edbo-600">Ax + BoTorch</span> 与 <span class="font-medium text-edbo-600">MNL-BO</span>（类别原生 surrogate）。
            </p>
        </div>

        {{-- 演示版 / 正式版切换（置于内容区顶部，随组件更新重渲染） --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white/70 px-4 py-3 backdrop-blur dark:border-white/10 dark:bg-white/5">
            <div>
                <flux:heading size="sm" class="font-medium">运行模式</flux:heading>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ $demoMode ? '演示版：使用合成目标函数模拟寻优过程。' : '正式版：基于你录入的真实实验数据推荐下一批实验。' }}
                </p>
            </div>
            <div class="flex items-center rounded-lg border border-slate-200 bg-white p-1 text-xs font-medium dark:border-white/10 dark:bg-slate-800">
                <button type="button" wire:click="$set('demoMode', true)" class="rounded-md px-3 py-1.5 transition {{ $demoMode ? 'bg-edbo-600 text-white shadow' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400' }}">
                    演示版
                </button>
                <button type="button" wire:click="$set('demoMode', false)" class="rounded-md px-3 py-1.5 transition {{ $demoMode ? 'text-slate-500 hover:text-slate-700 dark:text-slate-400' : 'bg-edbo-600 text-white shadow' }}">
                    正式版
                </button>
            </div>
        </div>

        {{-- 环境健康自检条 --}}
        <div wire:init="loadHealth" class="flex flex-wrap items-center gap-2 rounded-xl border border-white/50 bg-white/70 px-4 py-2.5 text-xs backdrop-blur dark:border-white/10 dark:bg-white/5">
            <span class="font-medium text-slate-500 dark:text-slate-400">运行环境：</span>
            @if($healthLoading)
                <flux:badge color="slate" size="sm">
                    <span class="inline-block size-3 animate-pulse rounded-full bg-slate-400"></span>
                    检查中…
                </flux:badge>
            @else
                <flux:badge :color="$health['micromamba'] ? 'emerald' : 'red'" size="sm">{{ $health['micromamba'] ? 'micromamba 就绪' : 'micromamba 缺失' }}</flux:badge>
                <flux:badge :color="($health['ax_importable'] ?? false) ? 'emerald' : 'slate'" size="sm">{{ ($health['ax_importable'] ?? false) ? 'Ax 已加载' : 'Ax 未安装' }}</flux:badge>
                <flux:badge :color="($health['mnl_importable'] ?? false) ? 'emerald' : 'slate'" size="sm">{{ ($health['mnl_importable'] ?? false) ? 'MNL-BO 已加载' : 'MNL-BO 未安装' }}</flux:badge>
                @unless($health['ready'])
                    <span class="ml-2 text-amber-600 dark:text-amber-400">注意：Ax 运行环境未就绪，请确认 edbo-ax（Py3.11）已安装。</span>
                @endunless
            @endif
        </div>

        {{-- 错误提示 --}}
        @if($error)
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                <strong class="font-semibold">运行失败：</strong>{{ $error }}
            </div>
        @endif

        {{-- 提示信息 --}}
        @if($info)
            <div class="rounded-xl border border-edbo-200 bg-edbo-50 p-4 text-sm text-edbo-700 dark:border-edbo-800 dark:bg-edbo-950/30 dark:text-edbo-300">
                {{ $info }}
            </div>
        @endif

        {{-- ============ 顶部菜单栏 ============ --}}
        <nav class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-white/70 p-1 backdrop-blur dark:border-white/10 dark:bg-white/5">
            <button type="button" wire:click="$set('activeMenu','space')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeMenu === 'space' ? 'bg-edbo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' }}">
                实验空间定义
            </button>
            <button type="button" wire:click="$set('activeMenu','history')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeMenu === 'history' ? 'bg-edbo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' }}">
                运行记录
            </button>
            <button type="button" wire:click="$set('activeMenu','prior')"
                class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeMenu === 'prior' ? 'bg-edbo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' }}">
                先验数据驱动
            </button>
        </nav>

        {{-- 任务排队时后台轮询运行状态（避免空闲时持续请求） --}}
        @if($runQueued)
            <div wire:poll.2s="checkRunStatus"></div>
        @endif

        {{-- ============ 实验空间定义 ============ --}}
        @if($activeMenu === 'space')
        <flux:card class="space-y-5">
            <div>
                <flux:heading size="lg">实验空间定义</flux:heading>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">每个参数填写名称。<strong>数值参数（连续区间）</strong>请直接填「最小值 / 最大值」两个数字，BO 会在该区间内连续探索；文字/类别参数请选「One-Hot」或「化学描述符」，再填离散取值（英文逗号分隔）。</p>
            </div>

            <div class="space-y-3">
                @foreach($parameters as $index => $param)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-400">参数 #{{ $index + 1 }}</span>
                            <flux:button wire:click="removeParameter({{ $index }})" size="xs" variant="ghost" class="text-red-500">移除</flux:button>
                        </div>
                        <flux:input wire:model="parameters.{{ $index }}.name" placeholder="参数名，如 温度(℃)" class="mb-2" />
                        @if(($param['encoding'] ?? 'numeric') === 'numeric')
                            {{-- 连续数值：明确的最小值 / 最大值 --}}
                            <div class="grid grid-cols-2 gap-2">
                                <flux:input wire:model="parameters.{{ $index }}.min" type="number" step="any" label="最小值" placeholder="如 80" />
                                <flux:input wire:model="parameters.{{ $index }}.max" type="number" step="any" label="最大值" placeholder="如 160" />
                            </div>
                            <flux:select wire:model="parameters.{{ $index }}.encoding" label="编码" class="mt-2">
                                <option value="numeric">数值（连续区间）</option>
                                <option value="ohe">One-Hot（类别推荐）</option>
                                <option value="resolve">化学描述符（需 rdkit）</option>
                            </flux:select>
                        @else
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <flux:input wire:model="parameters.{{ $index }}.values" placeholder="离散取值，如 A,B,C 或 1,2,3" />
                                <flux:select wire:model="parameters.{{ $index }}.encoding" label="编码">
                                    <option value="numeric">数值（连续区间）</option>
                                    <option value="ohe">One-Hot（类别推荐）</option>
                                    <option value="resolve">化学描述符（需 rdkit）</option>
                                </flux:select>
                            </div>
                        @endif
                    </div>
                @endforeach

                <flux:button wire:click="addParameter" variant="outline" size="sm" class="w-full">+ 添加参数</flux:button>
            </div>

            {{-- 优化目标（可多个，构成多目标优化）--}}
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="sm">优化目标（可多个）</flux:heading>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            定义多个目标即启用<strong>多目标优化</strong>（Ax 引擎的 qNEHVI 超体积采集），结果展示 Pareto 前沿。<br>
                            <span class="text-amber-600 dark:text-amber-400">注意：多目标仅支持 Ax 引擎；单目标时可任选引擎。</span>
                        </p>
                    </div>
                    <flux:badge color="purple" size="sm">{{ count($objectives) > 1 ? '多目标' : '单目标' }}</flux:badge>
                </div>
                @foreach($objectives as $oi => $obj)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-xs font-medium text-slate-400">目标 #{{ $oi + 1 }}</span>
                            <flux:button wire:click="removeObjective({{ $oi }})" size="xs" variant="ghost" class="text-red-500">移除</flux:button>
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <flux:input wire:model="objectives.{{ $oi }}.name" placeholder="指标名，如 yield / stability" />
                            <flux:select wire:model="objectives.{{ $oi }}.minimize" label="方向">
                                <option value="0">最大化 (maximize)</option>
                                <option value="1">最小化 (minimize)</option>
                            </flux:select>
                        </div>
                    </div>
                @endforeach
                <flux:button wire:click="addObjective" variant="outline" size="sm" class="w-full">+ 添加目标</flux:button>
            </div>

            <flux:separator />

            {{-- 优化设置 --}}
            <div class="grid grid-cols-2 gap-3">
                @php
                    $hasChemistry = collect($parameters ?? [])->contains(fn($p) => ($p['encoding'] ?? '') === 'resolve');
                @endphp
                <flux:select wire:model="engine" label="优化引擎" class="col-span-2">
                    @foreach(App\Livewire\EdboOptimizer::ENGINES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                @if($hasChemistry)
                    <p class="col-span-2 -mt-1 text-xs text-amber-600 dark:text-amber-400">
                        注意：检测到「化学描述符（resolve）」参数。Ax 引擎现已通过 edbo-chem（Mordred）计算每个分子的真实描述符，把分子类别变量编码为连续描述符向量输入高斯过程，保留结构相似性；
                        MNL-BO 引擎暂不支持 resolve，请使用 Ax 引擎。
                    </p>
                @endif
                <flux:input wire:model="batchSize" type="number" label="批量大小" min="1" max="20" />
                <flux:input wire:model="iterations" type="number" label="迭代轮数" min="1" max="50" />
                <flux:select wire:model="acquisition" label="采集函数">
                    @foreach(App\Livewire\EdboOptimizer::ACQUISITIONS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="initMethod" label="初始化方法">
                    @foreach(App\Livewire\EdboOptimizer::INIT_METHODS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>

            @if($engine === 'ax')
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-300">
                    <strong>Ax 引擎</strong>：使用 Sobol 准随机初始化 + BoTorch GP 优化，收敛更快。
                    连续数值参数直接走 RangeParameter，离散类别走 ChoiceParameter。
                    定义多个目标即自动启用 <strong>qNEHVI 多目标优化</strong>，输出 Pareto 前沿与超体积收敛。
                    @unless($healthLoading || ($health['ax_importable'] ?? false))
                        <span class="font-medium text-amber-600">注意：Ax 环境未就绪，请先完成安装。</span>
                    @endunless
                </div>
            @elseif($engine === 'mnl')
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <strong>MNL-BO 引擎</strong>：用多项 Logit（MNL）替代高斯过程做 surrogate，
                    类别变量（Base/Solvent）经 One-Hot 进入线性效用，<strong>天然无假顺序/假距离</strong>，
                    正好消除之前交叉验证 R² 为负的嫌疑根因。采集函数 EI_MNL / UCB_MNL。
                    纯 numpy 实现，<strong>无需 rdkit / torch</strong>。
                    @unless($healthLoading || ($health['mnl_importable'] ?? false))
                        <span class="font-medium text-amber-600">注意：MNL 环境未就绪（需 numpy）。</span>
                    @endunless
                </div>
            @endif

            <flux:separator />

            {{-- 先验数据导入区 --}}
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">真实实验结果录入</flux:heading>
                    <flux:badge color="blue" size="sm">历史数据 CSV</flux:badge>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    用于导入已有的历史实验数据。当前推荐批次的目标值建议直接在下方结果表格右侧填写，更加方便。
                    粘贴 CSV 数据：第一行为表头（参数名 + 目标列），后续每行一条真实实验记录。<br/>
                    <span class="font-medium">格式示例：</span>第一列起为各参数名，最后一列为目标名（如 yield）。
                </p>
                <flux:textarea
                    wire:model="priorData"
                    placeholder="温度(℃),时间(h),催化剂(mol%),yield&#10;80,1,1,45.2&#10;120,4,5,78.6&#10;160,8,10,92.1"
                    rows="5"
                    class="font-mono text-xs"
                />
                @if(!empty($priorData))
                    <p class="text-xs text-emerald-600 dark:text-emerald-400">
                        已输入先验数据，运行时将自动切换为 external 初始化模式
                    </p>
                @endif
            </div>

            <flux:button wire:click="runOptimization" variant="primary" class="w-full" :disabled="$running || $runQueued">
                @if($demoMode)
                    <span wire:loading.remove>运行贝叶斯优化（演示）</span>
                    <span wire:loading>演示优化中…（训练高斯过程）</span>
                @else
                    <span wire:loading.remove>生成下一批实验推荐（正式）</span>
                    <span wire:loading>推荐生成中…（基于真实数据建模）</span>
                @endif
            </flux:button>

            @if(! $demoMode)
            <button type="button" wire:click="newTask"
                class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">
                开启新课题（清空当前累积实验与推荐，从零开始）
            </button>
            @endif

            @if($runQueued)
                <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-300">
                    优化任务已加入队列，当前状态：<strong>{{ $runStatus }}</strong>。
                    预计数秒后自动刷新结果。
                </div>
            @endif

            @if($demoMode)
                <p class="text-center text-[11px] text-slate-400">
                    当前为<strong>演示版</strong>：使用合成目标函数展示寻优过程，所有最优解与收敛曲线均为模拟产物。
                </p>
            @else
                <p class="text-center text-[11px] text-amber-600 dark:text-amber-400">
                    当前为<strong>正式版</strong>：不做合成评估。首次运行将生成空间填充初始设计；完成实验后请在下方「推荐的下一批实验」表格右侧填入真实目标值并点击「保存并重新推荐」，模型将基于真实数据迭代。
                </p>
            @endif
        </flux:card>

        {{-- ============ 历史运行记录 ============ --}}
        @elseif($activeMenu === 'history')
        <div class="space-y-6">
            <flux:card>
                <div class="flex items-center justify-between">
                    <flux:heading size="lg"> 优化课题与批次</flux:heading>
                    <span class="text-xs text-slate-400">按课题分组 · 最近 50 次运行</span>
                </div>

                @forelse($history as $task)
                    <div class="mt-4 rounded-lg border border-slate-200 dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-3 py-2 dark:border-white/10">
                            <div class="flex items-center gap-2 text-sm">
                                <flux:badge color="indigo" size="sm">课题 {{ $task['short_task'] }}</flux:badge>
                                <span class="text-slate-500 dark:text-slate-400">{{ strtoupper($task['engine']) }} · 目标 {{ $task['target'] ?? '—' }} · 批量 {{ $task['batch_size'] ?? '—' }} / 迭代 {{ $task['iterations'] ?? '—' }}</span>
                            </div>
                            <span class="text-xs text-slate-400">{{ $task['batch_count'] }} 个批次 · 最近 {{ $task['created_at'] }}</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="text-xs uppercase text-slate-400">
                                    <tr>
                                        <th class="px-3 py-2">批次</th>
                                        <th class="px-3 py-2">Run</th>
                                        <th class="px-3 py-2">引擎</th>
                                        <th class="px-3 py-2">目标</th>
                                        <th class="px-3 py-2">批量</th>
                                        <th class="px-3 py-2">迭代</th>
                                        <th class="px-3 py-2">状态</th>
                                        <th class="px-3 py-2">时间</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($task['runs'] as $runItem)
                                        <tr class="border-t border-slate-100 dark:border-white/5 hover:bg-slate-50 dark:hover:bg-white/5 cursor-pointer {{ $selectedRunUuid === $runItem['uuid'] ? 'bg-edbo-50/50 dark:bg-edbo-950/30' : '' }}" wire:click="selectRun('{{ $runItem['uuid'] }}')">
                                            <td class="px-3 py-2 font-medium text-edbo-600 dark:text-edbo-300">第 {{ $runItem['batch'] }} 批</td>
                                            <td class="px-3 py-2 font-medium">{{ $runItem['short_uuid'] }}</td>
                                            <td class="px-3 py-2">{{ strtoupper($runItem['engine']) }}</td>
                                            <td class="px-3 py-2">{{ $runItem['target'] ?? '—' }}</td>
                                            <td class="px-3 py-2">{{ $runItem['batch_size'] ?? '—' }}</td>
                                            <td class="px-3 py-2">{{ $runItem['iterations'] ?? '—' }}</td>
                                            <td class="px-3 py-2">
                                                <flux:badge :color="match($runItem['status']) {
                                                    'completed' => 'emerald',
                                                    'failed' => 'red',
                                                    default => 'blue',
                                                }" size="sm">{{ ucfirst($runItem['status']) }}</flux:badge>
                                            </td>
                                            <td class="px-3 py-2">{{ $runItem['created_at'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @empty
                    <p class="mt-4 text-sm text-slate-500">暂无历史运行记录。</p>
                @endforelse
            </flux:card>

            @if($selectedRun)
                <flux:card>
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">运行详情</flux:heading>
                        <span class="text-xs text-slate-400">{{ $selectedRun['uuid'] }}</span>
                    </div>
                    {{-- 元信息：紧凑描述网格（一行多列，高密度） --}}
                    <dl class="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 border-t border-slate-200 pt-4 text-sm sm:grid-cols-3 lg:grid-cols-6 dark:border-white/10">
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">状态</dt>
                            <dd class="mt-0.5 font-semibold">{{ ucfirst($selectedRun['status']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">引擎 / 目标</dt>
                            <dd class="mt-0.5 font-semibold">{{ strtoupper($selectedRun['engine']) }} · {{ $selectedRun['target'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">批量 / 迭代</dt>
                            <dd class="mt-0.5 font-semibold">{{ $selectedRun['batch_size'] ?? '—' }} / {{ $selectedRun['iterations'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">参数数量</dt>
                            <dd class="mt-0.5 font-semibold">{{ count($selectedRun['parameters']) }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">先验数据</dt>
                            <dd class="mt-0.5 font-semibold">{{ $selectedRun['prior_results_count'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-[11px] uppercase tracking-wide text-slate-400">运行时间</dt>
                            <dd class="mt-0.5 text-xs leading-relaxed text-slate-600 dark:text-slate-300">开始 {{ $selectedRun['started_at'] ?? '—' }}<br>结束 {{ $selectedRun['finished_at'] ?? '—' }}</dd>
                        </div>
                    </dl>

                    {{-- 参数空间：横向标签，节省纵向空间 --}}
                    <div class="mt-4">
                        <flux:heading size="sm">参数空间</flux:heading>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach($selectedRun['parameters'] as $param)
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1 text-xs dark:bg-white/10">
                                    <span class="font-medium">{{ $param['name'] }}</span>
                                    @if(($param['encoding'] ?? 'numeric') === 'numeric' && count($param['values']) >= 2)
                                        <span class="text-slate-500 dark:text-slate-400">{{ $param['values'][0] }} ~ {{ $param['values'][count($param['values']) - 1] }}（连续）</span>
                                    @else
                                        <span class="text-slate-500 dark:text-slate-400">{{ implode(', ', $param['values']) }}</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    </div>

                    @if(isset($selectedRun['result']))
                        <div class="mt-4">
                            <flux:heading size="sm">运行结果</flux:heading>
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">当前运行已完成，以下为该次优化的结果摘要与模型推荐。</p>
                            <dl class="mt-3 grid grid-cols-3 gap-x-5 gap-y-3 border-t border-slate-200 pt-4 text-sm dark:border-white/10">
                                <div>
                                    <dt class="text-[11px] uppercase tracking-wide text-slate-400">最优产率</dt>
                                    <dd class="mt-0.5 font-semibold text-edbo-600 dark:text-edbo-300">{{ $selectedRun['result']['best'][$selectedRun['result']['target']] ?? '—' }}%</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-wide text-slate-400">评估次数</dt>
                                    <dd class="mt-0.5 font-semibold">{{ $selectedRun['result']['total_evaluations'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-wide text-slate-400">域规模</dt>
                                    <dd class="mt-0.5 font-semibold">{{ $selectedRun['result']['domain_size'] ?? '—' }}</dd>
                                </div>
                            </dl>

                            @if(!empty($selectedRun['result']['predicted_next']))
                                <div class="mt-4">
                                    <flux:heading size="sm">推荐下一批实验</flux:heading>
                                    <div class="mt-2 overflow-x-auto rounded-lg border border-slate-200 dark:border-white/10">
                                        <table class="w-full text-left text-sm">
                                            <thead class="bg-slate-50 text-xs uppercase text-slate-400 dark:bg-slate-900">
                                                <tr>
                                                    @foreach(array_keys($selectedRun['result']['predicted_next'][0]) as $column)
                                                        <th class="px-3 py-2">{{ $column === 'predicted_yield' ? '预测产率' : ucfirst(str_replace('_', ' ', $column)) }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($selectedRun['result']['predicted_next'] as $next)
                                                    <tr class="border-t border-slate-100 dark:border-white/5">
                                                        @foreach($next as $value)
                                                            <td class="px-3 py-2">{{ $value }}</td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @elseif(isset($selectedRun['result_error']))
                        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
                            <strong>结果读取失败：</strong> {{ $selectedRun['result_error'] }}
                        </div>
                    @endif
                </flux:card>
            @endif
        </div>

        {{-- 选中已完成运行后，于此嵌套展示其实验结果分析 --}}
        @if($selectedRun && $selectedRun['status'] === 'completed')
        <div class="space-y-6">
            @if($runQueued && !$result)
                <flux:card class="flex h-full min-h-[400px] flex-col items-center justify-center text-center">
                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 dark:bg-blue-950">
                        <flux:icon name="queue-list" class="h-8 w-8" />
                    </div>
                    <flux:heading size="lg" class="text-slate-500">任务已排队</flux:heading>
                    <p class="mt-2 max-w-sm text-sm text-slate-400">优化任务正在后台执行，结果准备好后会自动刷新。</p>
                </flux:card>
            @elseif(!$result)
                <flux:card class="flex h-full min-h-[400px] flex-col items-center justify-center text-center">
                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-edbo-50 text-edbo-500 dark:bg-edbo-950">
                        <flux:icon name="chart-bar" class="h-8 w-8" />
                    </div>
                    <flux:heading size="lg" class="text-slate-400">结果暂不可用</flux:heading>
                    <p class="mt-2 max-w-sm text-sm text-slate-400">该次运行暂无可用结果数据，请从上方列表选择一次已完成（completed）的运行查看分析。</p>
                </flux:card>
            @else
                {{-- 二级子菜单：最优条件 / 收敛曲线 / 结果分析 / 全部评估实验 --}}
                <nav class="flex flex-wrap items-center gap-1 rounded-xl border border-slate-200 bg-white/70 p-1 backdrop-blur dark:border-white/10 dark:bg-white/5">
                    <button type="button" wire:click="$set('activeResultSection','best')"
                        class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeResultSection === 'best' ? 'bg-edbo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' }}">
                        最优条件
                    </button>
                    <button type="button" wire:click="$set('activeResultSection','convergence')"
                        class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeResultSection === 'convergence' ? 'bg-edbo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' }}">
                        收敛曲线
                    </button>
                    <button type="button" wire:click="$set('activeResultSection','analysis')"
                        class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeResultSection === 'analysis' ? 'bg-edbo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' }}">
                        结果分析
                    </button>
                    <button type="button" wire:click="$set('activeResultSection','all')"
                        class="rounded-lg px-4 py-2 text-sm font-medium transition {{ $activeResultSection === 'all' ? 'bg-edbo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10' }}">
                        全部评估实验
                    </button>
                </nav>

                {{-- 模式横幅：提示本次结果来自演示还是正式推荐 --}}
                @php
                    $isRecommend = ($result['mode'] ?? 'simulation') === 'recommend';
                @endphp
                @if($isRecommend)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                        <strong>正式版推荐结果</strong>：本结果不含合成评估。
                        @if(($result['prior_results_count'] ?? 0) > 0)
                            基于 {{ $result['prior_results_count'] }} 条真实实验数据建模，下方「推荐的下一批实验」可直接用于真实迭代。
                        @else
                            尚未录入真实实验数据，下方为空间填充初始设计，建议先运行这批真实实验并录入结果。
                        @endif
                    </div>
                @endif

                {{-- 最优条件 --}}
                @if($activeResultSection === 'best')
                    @if(($result['multi_objective'] ?? false))
                        {{-- 多目标：Pareto 最优解集 --}}
                        <flux:card class="border-edbo-200 bg-gradient-to-br from-edbo-50 to-white dark:border-edbo-800 dark:from-edbo-950/40 dark:to-slate-900">
                            <div class="flex items-center justify-between">
                                <flux:heading size="lg">Pareto 最优解集</flux:heading>
                                <flux:badge color="emerald" size="lg">{{ count($result['pareto_front']) }} 个非支配解</flux:badge>
                            </div>
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                引擎：<span class="font-medium uppercase">{{ $result['engine'] ?? 'ax' }}</span> · 多目标采集 qNEHVI（超体积）·
                                共评估 {{ count($this->taskExperiments) }} 个实验。以下解互不支配，需按实际偏好取舍。
                            </p>
                            <div class="mt-3 overflow-x-auto rounded-lg border border-slate-200 dark:border-white/10">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-slate-50 text-xs uppercase text-slate-400 dark:bg-slate-900">
                                        <tr>
                                            @foreach($result['parameter_names'] as $pname)
                                                <th class="px-3 py-2">{{ $pname }}</th>
                                            @endforeach
                                            @foreach($result['objectives'] as $o)
                                                <th class="px-3 py-2">{{ $o['name'] }} ({{ $o['minimize'] ? 'min' : 'max' }})</th>
                                            @endforeach
                                            <th class="px-3 py-2">批次</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($result['pareto_front'] as $pf)
                                            <tr class="border-t border-slate-100 dark:border-white/5 {{ $loop->first ? 'bg-edbo-50/60 dark:bg-edbo-950/30' : '' }}">
                                                @foreach($result['parameter_names'] as $pname)
                                                    <td class="px-3 py-1.5 font-medium">{{ $pf[$pname] }}</td>
                                                @endforeach
                                                @foreach($result['objectives'] as $o)
                                                    <td class="px-3 py-1.5">{{ $pf[$o['name']] }}</td>
                                                @endforeach
                                                <td class="px-3 py-1.5 text-slate-400">{{ $pf['batch'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="mt-2 text-xs text-slate-400">首行（高亮）为距理想点最近的「折中解」，可作为单一推荐；其余为不同权衡倾向的备选。</p>
                        </flux:card>

                        {{-- Pareto 前沿 2D 散点 --}}
                        @php
                            $mooObjs = $result['objectives'];
                            $mx = $mooObjs[0]['name'];
                            $my = ($mooObjs[1]['name'] ?? $mooObjs[0]['name']);
                            $exps = $this->taskExperiments;
                            $xs = array_column($exps, $mx);
                            $ys = array_column($exps, $my);
                            $minX = min($xs); $maxX = max($xs); $minY = min($ys); $maxY = max($ys);
                            $sw = 380; $sh = 280; $sp = 45;
                            $rx = ($maxX - $minX) ?: 1; $ry = ($maxY - $minY) ?: 1;
                            $xf = fn($v) => $sp + (($v - $minX)/$rx) * ($sw - 2*$sp);
                            $yf = fn($v) => $sh - $sp - (($v - $minY)/$ry) * ($sh - 2*$sp);
                            $allPts = [];
                            foreach ($exps as $e) { $allPts[] = round($xf($e[$mx]),1).','.round($yf($e[$my]),1); }
                            $paretoPts = [];
                            foreach ($result['pareto_front'] as $e) { $paretoPts[] = round($xf($e[$mx]),1).','.round($yf($e[$my]),1); }
                        @endphp
                        <flux:card>
                            <flux:heading size="lg" class="mb-1">Pareto 前沿</flux:heading>
                            <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">横轴 {{ $mx }}（{{ $mooObjs[0]['minimize'] ? '越小越好' : '越大越好' }}），纵轴 {{ $my }}（{{ ($mooObjs[1]['minimize'] ?? false) ? '越小越好' : '越大越好' }}）。彩色点为非支配（Pareto 最优）解。</p>
                            <svg viewBox="0 0 {{ $sw }} {{ $sh }}" class="w-full" role="img" aria-label="Pareto 前沿">
                                <rect x="0" y="0" width="{{ $sw }}" height="{{ $sh }}" fill="#f8fafc" />
                                <line x1="{{ $sp }}" y1="{{ $sh-$sp }}" x2="{{ $sw-$sp }}" y2="{{ $sh-$sp }}" stroke="#cbd5e1" stroke-width="1"/>
                                <line x1="{{ $sp }}" y1="{{ $sp }}" x2="{{ $sp }}" y2="{{ $sh-$sp }}" stroke="#cbd5e1" stroke-width="1"/>
                                <text x="{{ $sp-5 }}" y="{{ $sh-$sp+16 }}" font-size="10" fill="#64748b" text-anchor="end">最小</text>
                                <text x="{{ $sw-$sp }}" y="{{ $sh-$sp+16 }}" font-size="10" fill="#64748b" text-anchor="end">{{ $maxX }}</text>
                                <text x="{{ $sp-6 }}" y="{{ $sp-4 }}" font-size="10" fill="#64748b" text-anchor="end">{{ $maxY }}</text>
                                @foreach($allPts as $p)
                                    <circle cx="{{ explode(',', $p)[0] }}" cy="{{ explode(',', $p)[1] }}" r="3" fill="#cbd5e1" opacity="0.7" />
                                @endforeach
                                @foreach($paretoPts as $p)
                                    <circle cx="{{ explode(',', $p)[0] }}" cy="{{ explode(',', $p)[1] }}" r="4.5" fill="#7f77dd" />
                                @endforeach
                            </svg>
                            <div class="mt-2 flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-edbo-500"></span>Pareto 最优</span>
                                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-slate-300"></span>其余评估点</span>
                            </div>
                        </flux:card>

                        {{-- 超体积收敛 --}}
                        @php
                            $hv = $result['hypervolume'];
                            $hvVals = array_column($hv, 'hypervolume');
                            $hvMin = min($hvVals); $hvMax = max($hvVals);
                            $hvRange = ($hvMax - $hvMin) ?: 1;
                            $hw = 760; $hh = 240; $hp = 40;
                            $n = count($hv);
                            $hx = fn($i) => $hp + ($n <= 1 ? 0 : ($i/($n-1))*($hw-2*$hp));
                            $hy = fn($v) => $hh - $hp - (($v-$hvMin)/$hvRange)*($hh-2*$hp);
                            $hvPts = [];
                            foreach ($hv as $i => $c) { $hvPts[] = round($hx($i),1).','.round($hy($c['hypervolume']),1); }
                            $hvPath = implode(' ', $hvPts);
                        @endphp
                        <flux:card>
                            <flux:heading size="lg" class="mb-1">超体积收敛</flux:heading>
                            <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">超体积（Hypervolume）随评估次数单调增长，衡量 Pareto 前沿覆盖的"质量空间"。曲线爬升即代表多目标优化在逼近前沿。</p>
                            <svg viewBox="0 0 {{ $hw }} {{ $hh }}" class="w-full" role="img" aria-label="超体积收敛">
                                <line x1="{{ $hp }}" y1="{{ $hh-$hp }}" x2="{{ $hw-$hp }}" y2="{{ $hh-$hp }}" stroke="#cbd5e1" stroke-width="1"/>
                                <line x1="{{ $hp }}" y1="{{ $hp }}" x2="{{ $hp }}" y2="{{ $hh-$hp }}" stroke="#cbd5e1" stroke-width="1"/>
                                @for($g=0;$g<=4;$g++)
                                    @php $gy = $hp + ($g/4)*($hh-2*$hp); $gv = round($hvMax - ($g/4)*$hvRange, 1); @endphp
                                    <line x1="{{ $hp }}" y1="{{ $gy }}" x2="{{ $hw-$hp }}" y2="{{ $gy }}" stroke="#e2e8f0" stroke-width="0.5"/>
                                    <text x="{{ $hp-6 }}" y="{{ $gy+4 }}" text-anchor="end" font-size="11" fill="#94a3b8">{{ $gv }}</text>
                                @endfor
                                <polyline points="{{ $hvPath }}" fill="none" stroke="#7f77dd" stroke-width="2.5"/>
                            </svg>
                        </flux:card>
                    @else
                    @if($isRecommend && empty($this->taskBest))
                        <flux:card>
                            <flux:heading size="lg">当前最优</flux:heading>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">正式版尚未录入真实实验数据，暂无可报告的"最优条件"。请先在左侧「真实实验结果录入」粘贴历史数据，或先按生成的初始设计运行实验并回填结果。</p>
                        </flux:card>
                    @else
                    <flux:card class="border-edbo-200 bg-gradient-to-br from-edbo-50 to-white dark:border-edbo-800 dark:from-edbo-950/40 dark:to-slate-900">
                        <div class="flex items-center justify-between">
                            <flux:heading size="lg">最优条件</flux:heading>
                            <flux:badge color="emerald" size="lg">最高 {{ $this->taskBest[$result['target']] }}%</flux:badge>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach($result['parameter_names'] as $pname)
                                <span class="rounded-lg bg-white/70 px-3 py-1.5 text-sm font-medium shadow-sm dark:bg-white/10">
                                    {{ $pname }}: <span class="text-edbo-700 dark:text-edbo-300">{{ $this->taskBest[$pname] }}</span>
                                </span>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                            引擎：<span class="font-medium uppercase">{{ $result['engine'] ?? 'ax' }}</span> ·
                            本课题共评估 {{ count($this->taskExperiments) }} 个实验（域规模 {{ $result['domain_size'] }}），耗时寻优 {{ $result['iterations'] }} 轮。
                            @if(($result['prior_results_count'] ?? 0) > 0)
                                <span class="text-edbo-600 dark:text-edbo-400">其中 {{ $result['prior_results_count'] }} 条来自先验数据导入，初始化模式：external。</span>
                            @endif
                        </p>
                    </flux:card>
                    @endif

                    {{-- 预测 / 推荐下一批 --}}
                    @if(!empty($result['predicted_next']))
                        @php
                            $predRows = $result['predicted_next'];
                            $predFirst = $predRows[0] ?? [];
                            $predCol = 'predicted_' . $result['target'];
                            $hasPred = isset($predFirst[$predCol]);
                            $hasVar = isset($predFirst['variance']);
                        @endphp
                        <flux:card>
                            <flux:heading size="lg" class="mb-1">
                                @if($isRecommend) 推荐的下一批实验（正式版） @else 模型推荐的下一批实验 @endif
                            </flux:heading>
                            <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">
                                @if($isRecommend)
                                    @if(($result['prior_results_count'] ?? 0) > 0)
                                        基于 {{ $result['prior_results_count'] }} 条真实实验数据训练的模型，推荐 {{ count($predRows) }} 个信息量最大的候选（含模型预测值）。请在真实实验中验证后，再作为新先验录入，循环迭代。
                                    @else
                                        尚未录入真实实验数据：以下为空间填充初始设计，建议先按此运行一批真实实验并录入结果，再运行以生成数据驱动的推荐。
                                    @endif
                                @else
                                    基于已训练的模型，在全域中预测目标最高的未实验点（含预测方差，越大越不确定）。
                                @endif
                            </p>
                            @if(! $demoMode && $taskUuid)
                            <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg bg-edbo-50 px-3 py-2 text-xs text-edbo-700 dark:bg-edbo-950/30 dark:text-edbo-200">
                                <flux:badge color="indigo" size="sm">课题 {{ substr($taskUuid, 0, 8) }}</flux:badge>
                                <span>已累积 <strong>{{ count($experimentLog) }}</strong> 条真实实验作为本次建模先验；点击「保存并重新推荐」将在<strong>同一课题</strong>下追加下一批次，而非另起新任务。</span>
                            </div>
                            @endif
                            <div class="overflow-x-auto">
                            @php
                                $recObjectives = $result['objectives'] ?? [['name' => $result['target'] ?? 'yield']];
                                $showInlineInputs = ! $demoMode && ($result['mode'] ?? 'simulation') === 'recommend';
                            @endphp
                            <table class="w-full text-left text-sm">
                                <thead class="text-xs uppercase text-slate-400">
                                    <tr>
                                        @foreach($result['parameter_names'] as $pname)
                                            <th class="px-3 py-2">{{ $pname }}</th>
                                        @endforeach
                                        @if($showInlineInputs)
                                            @foreach($recObjectives as $obj)
                                                <th class="px-3 py-2">结果（{{ $obj['name'] }}）</th>
                                            @endforeach
                                        @endif
                                        @if($hasPred)<th class="px-3 py-2">预测{{ $result['target'] }}</th>@endif
                                        @if($hasVar)<th class="px-3 py-2">方差</th>@endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($predRows as $row)
                                        <tr class="border-t border-slate-100 dark:border-white/5">
                                            @foreach($result['parameter_names'] as $pname)
                                                <td class="px-3 py-2 font-medium">{{ $row[$pname] }}</td>
                                            @endforeach
                                            @if($showInlineInputs)
                                                @foreach($recObjectives as $obj)
                                                    <td class="px-3 py-2">
                                                        <input type="number" step="any"
                                                            wire:model="recommendedInputs.{{ $loop->parent->index }}.{{ $obj['name'] }}"
                                                            placeholder="填写 {{ $obj['name'] }}"
                                                            class="w-full min-w-[100px] rounded-md border border-slate-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800"
                                                        />
                                                    </td>
                                                @endforeach
                                            @endif
                                            @if($hasPred)<td class="px-3 py-2 text-edbo-700 dark:text-edbo-300">{{ $row[$predCol] }}</td>@endif
                                            @if($hasVar)<td class="px-3 py-2 text-slate-400">{{ $row['variance'] }}</td>@endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        </flux:card>

                        @if($showInlineInputs)
                        <flux:card class="border-amber-200 bg-amber-50/50 dark:border-amber-800/50 dark:bg-amber-950/20">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <flux:heading size="md">录入真实实验结果</flux:heading>
                                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                        按上方推荐条件完成真实实验后，在表格右侧「结果」列填入真实目标值，点击「保存并重新推荐」进入下一轮迭代。
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="newTask"
                                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">
                                        开启新课题
                                    </button>
                                    <button type="button" wire:click="seedPriorFromRecommended"
                                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">
                                        清空已填结果
                                    </button>
                                    <button type="button" wire:click="runOptimization"
                                        class="rounded-lg bg-edbo-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-edbo-500">
                                        保存并重新推荐
                                    </button>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                                提示：左侧「真实实验结果录入」仍可粘贴历史 CSV 数据；本表格用于快速录入当前推荐批次的结果。所有批次（含历史 CSV）会累积到同一课题下重新建模。若需更换参数空间 / 目标重新开始，请点击「开启新课题」。
                            </p>
                        </flux:card>
                        @endif
                    @endif
                    @endif
                @endif

                {{-- 收敛曲线 --}}
                @if($activeResultSection === 'convergence')
                    @if($isRecommend || empty($result['convergence']))
                        <flux:card>
                            <flux:heading size="lg">收敛曲线</flux:heading>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                @if($isRecommend)
                                    正式版不做合成评估，因此没有收敛曲线。请关注上方「推荐的下一批实验」进行真实迭代。
                                @else
                                    本次运行暂无可用的收敛数据。
                                @endif
                            </p>
                        </flux:card>
                    @else
                    @php
                        $conv = $result['convergence'] ?? [];
                        $w = 760; $h = 260; $pad = 40;
                        $n = count($conv);
                        $isMooConv = ($result['multi_objective'] ?? false);
                        if ($isMooConv) {
                            // 多目标：convergence 是超体积序列（标量数组）
                            $vals = array_map(fn($v) => is_array($v) ? (float) reset($v) : (float) $v, $conv);
                            $minV = min($vals); $maxV = max($vals);
                            $margin = ($maxV - $minV) * 0.1 + 1e-9;
                            $minY = $minV - $margin; $maxY = $maxV + $margin;
                            $xOf = fn($i) => $pad + ($n <= 1 ? 0 : ($i / ($n - 1)) * ($w - 2 * $pad));
                            $yOf = fn($v) => $h - $pad - (($v - $minY) / ($maxY - $minY)) * ($h - 2 * $pad);
                            $hvPts = [];
                            foreach ($vals as $i => $v) {
                                $hvPts[] = round($xOf($i), 1).','.round($yOf($v), 1);
                            }
                            $hvPath = implode(' ', $hvPts);
                        } else {
                            // 单目标：convergence 是 {best_yield, mean_yield} 数组
                            $minY = 0; $maxY = 100;
                            $xOf = fn($i) => $pad + ($n <= 1 ? 0 : ($i / ($n - 1)) * ($w - 2 * $pad));
                            $yOf = fn($v) => $h - $pad - (($v - $minY) / ($maxY - $minY)) * ($h - 2 * $pad);
                            $bestPts = []; $meanPts = [];
                            foreach ($conv as $i => $c) {
                                $bestPts[] = round($xOf($i), 1).','.round($yOf($c['best_yield'] ?? 0), 1);
                                $meanPts[] = round($xOf($i), 1).','.round($yOf($c['mean_yield'] ?? 0), 1);
                            }
                            $bestPath = implode(' ', $bestPts);
                            $meanPath = implode(' ', $meanPts);
                        }
                    @endphp
                    <flux:card>
                        @if($isMooConv)
                            <flux:heading size="lg" class="mb-1">超体积收敛曲线</flux:heading>
                            <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">紫线为多目标 Pareto 前沿所覆盖的超体积（Hypervolume）随评估次数的累计变化。上升即代表找到了更多/更好的非支配解。</p>
                        @else
                            <flux:heading size="lg" class="mb-1">收敛曲线</flux:heading>
                            <p class="mb-3 text-xs text-slate-500 dark:text-slate-400">蓝线为累计最优产率，灰线为累计平均产率。曲线爬升即代表 EDBO 在逐步逼近最优。</p>
                        @endif
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" role="img" aria-label="收敛曲线">
                            {{-- 网格与坐标轴 --}}
                            <line x1="{{ $pad }}" y1="{{ $h-$pad }}" x2="{{ $w-$pad }}" y2="{{ $h-$pad }}" stroke="#cbd5e1" stroke-width="1"/>
                            <line x1="{{ $pad }}" y1="{{ $pad }}" x2="{{ $pad }}" y2="{{ $h-$pad }}" stroke="#cbd5e1" stroke-width="1"/>
                            @for($g=0;$g<=4;$g++)
                                @php
                                    $gy = $pad + ($g/4)*($h-2*$pad);
                                    $gv = round($maxY - ($g/4)*($maxY-$minY), $isMooConv ? 2 : 0);
                                @endphp
                                <line x1="{{ $pad }}" y1="{{ $gy }}" x2="{{ $w-$pad }}" y2="{{ $gy }}" stroke="#e2e8f0" stroke-width="0.5"/>
                                <text x="{{ $pad-6 }}" y="{{ $gy+4 }}" text-anchor="end" font-size="11" fill="#94a3b8">{{ $gv }}</text>
                            @endfor
                            @if($isMooConv)
                                {{-- 超体积线 --}}
                                <polyline points="{{ $hvPath }}" fill="none" stroke="#7c3aed" stroke-width="2.5"/>
                            @else
                                {{-- 平均线 --}}
                                <polyline points="{{ $meanPath }}" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="4 3" opacity="0.8"/>
                                {{-- 最优线 --}}
                                <polyline points="{{ $bestPath }}" fill="none" stroke="#2563eb" stroke-width="2.5"/>
                            @endif
                        </svg>
                        @if($isMooConv)
                            <div class="mt-2 flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                                <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 bg-purple-600"></span>累计超体积</span>
                            </div>
                        @else
                            <div class="mt-2 flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                                <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 bg-edbo-600"></span>累计最优</span>
                                <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 bg-slate-400"></span>累计平均</span>
                            </div>
                        @endif
                    </flux:card>
                    @endif
                @endif

                {{-- 结果分析 --}}
                @if($activeResultSection === 'analysis')
                    @if($analysisData)
                        <flux:card class="mt-6">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <flux:heading size="lg">结果分析</flux:heading>
                                <span class="text-xs text-slate-400">参数重要性 · 交叉验证 · Slice Plot · Contour Map</span>
                            </div>

                            {{-- 参数选择区 --}}
                            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Slice Plot 参数</label>
                                    <select wire:model="sliceParam" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-edbo-500 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                                        @foreach($result['parameter_names'] as $name)
                                            <option value="{{ $name }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Contour Map 参数 X</label>
                                    <select wire:model="contourParamX" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-edbo-500 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                                        @foreach($result['parameter_names'] as $name)
                                            <option value="{{ $name }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Contour Map 参数 Y</label>
                                    <select wire:model="contourParamY" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-edbo-500 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                                        @foreach($result['parameter_names'] as $name)
                                            <option value="{{ $name }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-3">
                                {{-- 重要性分析图 --}}
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                    <flux:heading size="md" class="mb-1">重要性分析</flux:heading>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">极差分析：参数取值对目标的影响范围（越大越重要）。</p>
                                    <div class="aspect-[4/3] max-h-64">
                                        @php
                                            $importance = $analysisData['importance'] ?? [];
                                            $maxNorm = max(array_column($importance, 'normalized')) ?: 1;
                                            $w = 320; $h = 240; $pad = 40;
                                            $barW = ($w - 2 * $pad) / count($importance);
                                            $barW = max(15, min(60, $barW));
                                            $gap = ($w - 2 * $pad - $barW * count($importance)) / (count($importance) - 1);
                                        @endphp
                                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-full w-full" role="img" aria-label="参数重要性分析">
                                            <rect x="0" y="0" width="{{ $w }}" height="{{ $h }}" fill="#f8fafc" />
                                            <text x="{{ $pad - 5 }}" y="{{ $pad - 5 }}" font-size="11" fill="#64748b" text-anchor="end">100%</text>
                                            <text x="{{ $pad - 5 }}" y="{{ $h - $pad + 15 }}" font-size="11" fill="#64748b" text-anchor="end">0%</text>
                                            <line x1="{{ $pad }}" y1="{{ $pad }}" x2="{{ $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            @for($i = 0; $i < count($importance); $i++)
                                                @php
                                                    $imp = $importance[$i];
                                                    $x = $pad + $i * ($barW + $gap);
                                                    $height = ($imp['normalized'] / $maxNorm) * ($h - 2 * $pad);
                                                    $y = $h - $pad - $height;
                                                @endphp
                                                <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barW }}" height="{{ $height }}" fill="#3b82f6" />
                                                <text x="{{ $x + $barW/2 }}" y="{{ $h - $pad + 15 }}" font-size="10" fill="#64748b" text-anchor="middle">{{ substr($imp['name'], 0, 6) }}</text>
                                                <text x="{{ $x + $barW/2 }}" y="{{ $y - 5 }}" font-size="10" fill="#1e40af" text-anchor="middle">{{ $imp['normalized'] }}%</text>
                                            @endfor
                                        </svg>
                                    </div>
                                </div>

                                {{-- 交叉验证图（散点图：actual vs predicted） --}}
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                    <flux:heading size="md" class="mb-1">交叉验证</flux:heading>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">R² = {{ $analysisData['cross_validation']['r_squared'] }} ({{ $analysisData['cross_validation']['method'] }})。散点越贴近 y=x 红线，模型预测越准。</p>
                                    <div class="aspect-[4/3] max-h-64">
                                        @php
                                            $cv = $analysisData['cross_validation'];
                                            $w = 320; $h = 240; $pad = 40;
                                            $min = 0; $max = 100;
                                            $xOf = fn($v) => $pad + (($v - $min) / ($max - $min)) * ($w - 2 * $pad);
                                            $yOf = fn($v) => $h - $pad - (($v - $min) / ($max - $min)) * ($h - 2 * $pad);
                                        @endphp
                                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-full w-full" role="img" aria-label="交叉验证">
                                            <rect x="0" y="0" width="{{ $w }}" height="{{ $h }}" fill="#f8fafc" />
                                            <line x1="{{ $pad }}" y1="{{ $pad }}" x2="{{ $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $pad }}" stroke="#ef4444" stroke-width="1" stroke-dasharray="4 3" opacity="0.7"/>
                                            <text x="{{ $pad - 5 }}" y="{{ $pad - 5 }}" font-size="11" fill="#64748b" text-anchor="end">{{ $max }}</text>
                                            <text x="{{ $pad - 5 }}" y="{{ $h - $pad + 15 }}" font-size="11" fill="#64748b" text-anchor="end">{{ $min }}</text>
                                            <text x="{{ $w - $pad }}" y="{{ $h - $pad + 22 }}" font-size="10" fill="#64748b" text-anchor="end">实际值</text>
                                            <text x="{{ $pad - 5 }}" y="{{ $pad - 10 }}" font-size="10" fill="#64748b" text-anchor="end">预测值</text>
                                            @if($cv['available'] && !empty($cv['points']))
                                                @foreach($cv['points'] as $p)
                                                    <circle cx="{{ round($xOf($p['actual']), 1) }}" cy="{{ round($yOf($p['predicted']), 1) }}" r="3.5" fill="#3b82f6" opacity="0.7" />
                                                @endforeach
                                            @else
                                                <text x="{{ $w/2 }}" y="{{ $h/2 }}" font-size="12" fill="#94a3b8" text-anchor="middle">数据不足</text>
                                            @endif
                                        </svg>
                                    </div>
                                    <div class="mt-2 flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                                        <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-full bg-blue-500"></span>实际 vs 预测</span>
                                        <span class="flex items-center gap-1"><span class="inline-block h-0.5 w-4 bg-red-500"></span>理想 y=x</span>
                                    </div>
                                </div>

                                {{-- Slice Plot --}}
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                    <flux:heading size="md" class="mb-1">Slice Plot</flux:heading>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">固定其他参数为最优值，遍历 {{ $analysisData['slice_plot']['param'] }} 对产率的影响。</p>
                                    <div class="aspect-[4/3] max-h-64">
                                        @php
                                            $slice = $analysisData['slice_plot'];
                                            if (!empty($slice['points'])) {
                                                $points = $slice['points'];
                                                $w = 320; $h = 240; $pad = 40;
                                                $minY = 0; $maxY = 100;
                                                $xOf = fn($i) => $pad + ($i / (count($points) - 1)) * ($w - 2 * $pad);
                                                $yOf = fn($v) => $h - $pad - (($v - $minY) / ($maxY - $minY)) * ($h - 2 * $pad);
                                                $pts = [];
                                                foreach ($points as $i => $p) {
                                                    $pts[] = round($xOf($i), 1).','.round($yOf($p['yield']), 1);
                                                }
                                                $path = implode(' ', $pts);
                                            }
                                        @endphp
                                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-full w-full" role="img" aria-label="Slice Plot">
                                            <rect x="0" y="0" width="{{ $w }}" height="{{ $h }}" fill="#f8fafc" />
                                            <text x="{{ $pad - 5 }}" y="{{ $pad - 5 }}" font-size="11" fill="#64748b" text-anchor="end">100</text>
                                            <text x="{{ $pad - 5 }}" y="{{ $h - $pad + 15 }}" font-size="11" fill="#64748b" text-anchor="end">0</text>
                                            <line x1="{{ $pad }}" y1="{{ $pad }}" x2="{{ $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            @if(!empty($slice['points']))
                                                <polyline points="{{ $path }}" fill="none" stroke="#10b981" stroke-width="2"/>
                                                @foreach($slice['points'] as $i => $p)
                                                    <circle cx="{{ round($xOf($i), 1) }}" cy="{{ round($yOf($p['yield']), 1) }}" r="3" fill="#10b981" />
                                                @endforeach
                                            @else
                                                <text x="{{ $w/2 }}" y="{{ $h/2 }}" font-size="12" fill="#94a3b8" text-anchor="middle">无数据</text>
                                            @endif
                                        </svg>
                                    </div>
                                </div>

                                {{-- Contour Map --}}
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                    <flux:heading size="md" class="mb-1">Contour Map</flux:heading>
                                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">固定其他参数为最优值，{{ $analysisData['contour']['param_x'] }} vs {{ $analysisData['contour']['param_y'] }} 的产率分布。</p>
                                    <div class="aspect-[4/3] max-h-64">
                                        @php
                                            $contour = $analysisData['contour'];
                                            $grid = $contour['grid'];
                                            $gridSize = $contour['grid_size'];
                                            $minX = $contour['min_x'];
                                            $maxX = $contour['max_x'];
                                            $minY = $contour['min_y'];
                                            $maxY = $contour['max_y'];
                                            $minYield = $contour['min_yield'];
                                            $maxYield = $contour['max_yield'];
                                            $rangeYield = $maxYield - $minYield ?: 1;
                                            $w = 320; $h = 240; $pad = 20;
                                            $cellW = ($w - 2 * $pad) / $gridSize;
                                            $cellH = ($h - 2 * $pad) / $gridSize;
                                        @endphp
                                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-full w-full" role="img" aria-label="Contour Map">
                                            <rect x="0" y="0" width="{{ $w }}" height="{{ $h }}" fill="#f8fafc" />
                                            <text x="{{ $pad - 5 }}" y="{{ $pad - 5 }}" font-size="10" fill="#64748b" text-anchor="end">{{ round($maxYield) }}</text>
                                            <text x="{{ $pad - 5 }}" y="{{ $h - $pad + 15 }}" font-size="10" fill="#64748b" text-anchor="end">{{ round($minYield) }}</text>
                                            <line x1="{{ $pad }}" y1="{{ $pad }}" x2="{{ $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}" stroke="#cbd5e1" stroke-width="1"/>
                                            @for($i = 0; $i < $gridSize; $i++)
                                                @php
                                                    $x = $minX + ($i / ($gridSize - 1)) * ($maxX - $minX);
                                                    $xPos = $pad + $i * $cellW;
                                                @endphp
                                                <text x="{{ $xPos }}" y="{{ $h - $pad + 15 }}" font-size="8" fill="#64748b" text-anchor="middle">{{ round($x, 1) }}</text>
                                            @endfor
                                            @for($j = 0; $j < $gridSize; $j++)
                                                @php
                                                    $y = $minY + ($j / ($gridSize - 1)) * ($maxY - $minY);
                                                    $yPos = $pad + $j * $cellH;
                                                @endphp
                                                <text x="{{ $pad - 10 }}" y="{{ $yPos + 3 }}" font-size="8" fill="#64748b" text-anchor="end">{{ round($y, 1) }}</text>
                                            @endfor
                                            @for($i = 0; $i < $gridSize; $i++)
                                                @for($j = 0; $j < $gridSize; $j++)
                                                    @php
                                                        $val = $grid[$i][$j];
                                                        $norm = ($val - $minYield) / $rangeYield;
                                                        $r = round(255 * (1 - $norm));
                                                        $g = round(255 * $norm);
                                                        $b = 100;
                                                        $fill = "rgb({$r},{$g},{$b})";
                                                        $x = $pad + $i * $cellW;
                                                        $y = $pad + $j * $cellH;
                                                    @endphp
                                                    <rect x="{{ $x }}" y="{{ $y }}" width="{{ $cellW }}" height="{{ $cellH }}" fill="{{ $fill }}" />
                                                @endfor
                                            @endfor
                                        </svg>
                                    </div>
                                </div>
                            </div>
                        </flux:card>
                    @endif
                @endif

                {{-- 全部评估实验 --}}
                @if($activeResultSection === 'all')
                    <flux:card>
                        <flux:heading size="lg" class="mb-3">全部评估实验（{{ count($this->taskExperiments) }}）</flux:heading>
                        <div class="max-h-80 overflow-y-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="sticky top-0 bg-white text-xs uppercase text-slate-400 dark:bg-slate-900">
                                    <tr>
                                        @foreach($result['parameter_names'] as $pname)
                                            <th class="px-3 py-2">{{ $pname }}</th>
                                        @endforeach
                                        @if(($result['multi_objective'] ?? false))
                                            @foreach($result['objectives'] as $o)
                                                <th class="px-3 py-2">{{ $o['name'] }}</th>
                                            @endforeach
                                        @else
                                            <th class="px-3 py-2">{{ $result['target'] }}</th>
                                        @endif
                                        <th class="px-3 py-2">批次</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->taskExperiments as $row)
                                        <tr class="border-t border-slate-100 dark:border-white/5 {{ $row['batch'] === 1 ? 'bg-edbo-50/40 dark:bg-edbo-950/20' : '' }}">
                                            @foreach($result['parameter_names'] as $pname)
                                                <td class="px-3 py-1.5">{{ $row[$pname] }}</td>
                                            @endforeach
                                            @if(($result['multi_objective'] ?? false))
                                                @foreach($result['objectives'] as $o)
                                                    <td class="px-3 py-1.5 font-medium">{{ $row[$o['name']] }}</td>
                                                @endforeach
                                            @else
                                                <td class="px-3 py-1.5 font-medium">{{ $row[$result['target']] }}%</td>
                                            @endif
                                            <td class="px-3 py-1.5 text-slate-400">{{ $row['batch'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </flux:card>
                @endif
            @endif
        </div>
        @endif

        {{-- ============ 先验数据驱动 ============ --}}
        @elseif($activeMenu === 'prior')
        <livewire:prior-data-assistant />
        @endif
    </div>
