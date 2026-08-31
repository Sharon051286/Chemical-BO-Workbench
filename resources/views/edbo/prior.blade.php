{{-- 先验数据驱动：LLM + RAG（RAGFlow）建议参数与参数空间 --}}
<div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">先验数据驱动</flux:heading>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                汇聚历史运行、上传文档与手动粘贴，借助 RAGFlow（RAG + LLM）生成参数、参数空间与候选参数组建议。
            </p>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium
            {{ $ragflowReady ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' }}">
            <span class="h-1.5 w-1.5 rounded-full {{ $ragflowReady ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
            {{ $ragflowReady ? 'RAGFlow 已连接' : 'RAGFlow 未连接（启发式模式）' }}
        </span>
    </div>

    {{-- 提示信息 --}}
    @if($info)
        <div class="rounded-lg border border-edbo-200 bg-edbo-50 px-4 py-2 text-sm text-edbo-700 dark:border-edbo-800 dark:bg-edbo-950/40 dark:text-edbo-300">{{ $info }}</div>
    @endif
    @if($error)
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">{{ $error }}</div>
    @endif

    {{-- 优化目标 --}}
    <flux:card class="space-y-2">
        <flux:heading size="md">优化目标</flux:heading>
        <textarea wire:model="objective" rows="2"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-edbo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white"
            placeholder="例如：最大化产率，同时尽量降低成本与反应时间"></textarea>
    </flux:card>

    {{-- 语料管理 --}}
    <flux:card class="space-y-4">
        <flux:heading size="md">语料管理</flux:heading>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <button type="button" wire:click="syncHistoricalRuns"
                class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">
                同步历史运行
            </button>

            <div class="rounded-lg border border-slate-200 px-3 py-2 dark:border-white/10">
                <input type="file" wire:model="upload" class="w-full text-xs" />
                <button type="button" wire:click="uploadDocument"
                    class="mt-2 w-full rounded-md bg-slate-800 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-700">
                    上传文档
                </button>
            </div>

            <div class="rounded-lg border border-slate-200 px-3 py-2 dark:border-white/10">
                <textarea wire:model="pasteText" rows="2" placeholder="粘贴实验记录 / 文献片段…"
                    class="w-full rounded-md border border-slate-300 px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-edbo-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white"></textarea>
                <button type="button" wire:click="addPaste"
                    class="mt-2 w-full rounded-md bg-slate-800 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-700">
                    添加粘贴文本
                </button>
            </div>
        </div>

        <button type="button" wire:click="seedSampleData"
            class="rounded-lg border border-dashed border-edbo-400 px-4 py-2 text-sm font-medium text-edbo-700 transition hover:bg-edbo-50 dark:border-edbo-600 dark:text-edbo-300 dark:hover:bg-edbo-950/40">
            加载 / 重新加载样例数据
        </button>

        {{-- 语料清单 --}}
        @if(!empty($corpus))
            <div class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-white/5 dark:border-white/10">
                @foreach($corpus as $entry)
                    <div class="flex items-center justify-between px-3 py-2 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $entry['name'] }}</p>
                            <p class="text-xs text-slate-400">{{ $entry['type'] }} · {{ $entry['added_at'] }}</p>
                        </div>
                        <button type="button" wire:click="removeEntry('{{ $entry['id'] }}')"
                            class="ml-3 shrink-0 rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40">
                            删除
                        </button>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-sm text-slate-400">暂无语料。请同步历史运行、上传或粘贴文档。</p>
        @endif

        <button type="button" wire:click="pushToRagFlow" @if(!$ragflowReady) disabled @endif
            class="rounded-lg bg-edbo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-edbo-500 disabled:cursor-not-allowed disabled:opacity-40">
            推送语料到 RAGFlow 知识库
        </button>
        @if(!$ragflowReady)
            <p class="text-xs text-slate-400">未配置 RAGFlow 时该按钮不可用；未连接时仍可用「启发式模式」基于历史统计生成建议。</p>
        @endif
    </flux:card>

    {{-- RAGFlow 知识库管理 --}}
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="md">RAGFlow 知识库</flux:heading>
            @if($ragflowReady)
                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium
                    {{ $ragflowHealth === 'ok' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : ($ragflowHealth === 'down' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' : 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300') }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $ragflowHealth === 'ok' ? 'bg-emerald-500' : ($ragflowHealth === 'down' ? 'bg-red-500' : 'bg-slate-400') }}"></span>
                    {{ $ragflowHealth === 'ok' ? '已连通' : ($ragflowHealth === 'down' ? '不可达' : '未检查') }}
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>未配置
                </span>
            @endif
        </div>

        @if(!$ragflowReady)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                尚未连接 RAGFlow。请先在本地用 Docker 启动 RAGFlow（见 <code>docker/ragflow/docker-compose.yml</code>），
                在 <code>.env</code> 填入 <code>RAGFLOW_API_URL</code> 与 <code>RAGFLOW_API_KEY</code>，再运行
                <code>php artisan ragflow:setup</code> 建库。未连接时仍可用「启发式模式」基于历史统计生成建议。
            </div>
        @else
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="checkConnection"
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium transition hover:bg-slate-100 dark:border-white/10 dark:hover:bg-white/10">
                    检查连接
                </button>
                <button type="button" wire:click="refreshRagFlow" @if($ragflowHealth !== 'ok') disabled @endif
                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-white/10 dark:hover:bg-white/10">
                    刷新文档
                </button>
            </div>

            @if($ragflowHealth !== 'ok')
                <p class="text-sm text-slate-400">点击「检查连接」以验证 RAGFlow 是否可达并加载知识库文档。</p>
            @elseif(!empty($ragflowDocs))
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-400">
                            <tr>
                                <th class="px-3 py-2">文档</th>
                                <th class="px-3 py-2">大小</th>
                                <th class="px-3 py-2">解析状态</th>
                                <th class="px-3 py-2">分块</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ragflowDocs as $doc)
                                <tr class="border-t border-slate-100 dark:border-white/5">
                                    <td class="px-3 py-1.5 font-medium">{{ $doc['name'] }}</td>
                                    <td class="px-3 py-1.5 text-slate-500">
                                        {{ $doc['size'] >= 1024 ? number_format($doc['size'] / 1024, 1) . ' KB' : $doc['size'] . ' B' }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        @php
                                            $run = $doc['run'];
                                            $st = match ($run) {
                                                'DONE', '3' => ['label' => '已就绪', 'cls' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
                                                'RUNNING', '1' => ['label' => '解析中', 'cls' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'],
                                                'FAIL', '4' => ['label' => '失败', 'cls' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
                                                'CANCEL', '2' => ['label' => '已取消', 'cls' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
                                                default => ['label' => '未解析', 'cls' => 'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300'],
                                            };
                                        @endphp
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $st['cls'] }}">{{ $st['label'] }}</span>
                                        @if($run === 'RUNNING' || $run === '1')
                                            <span class="ml-1 text-xs text-slate-400">稍后刷新</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 text-slate-500">{{ $doc['chunk_count'] }}</td>
                                    <td class="px-3 py-1.5 text-right">
                                        <button type="button" wire:click="deleteRagDoc('{{ $doc['id'] }}')"
                                            class="rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40">
                                            删除
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-slate-400">知识库暂无文档。请在上方「语料管理」中同步历史运行或上传文档，再点击「推送语料到 RAGFlow 知识库」。</p>
            @endif
        @endif
    </flux:card>

    {{-- 生成建议 --}}
    <div>
        <button type="button" wire:click="generate" @if($loading) disabled @endif
            class="rounded-lg bg-edbo-600 px-5 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-edbo-500 disabled:opacity-50">
            @if($loading) 生成中… @else 生成参数建议 @endif
        </button>
    </div>

    {{-- 建议结果 --}}
    @if($suggestion)
        <flux:card class="space-y-5">
            <div class="flex items-center justify-between">
                <flux:heading size="md">建议结果</flux:heading>
                @php
                    $srcLabel = match ($suggestion['source'] ?? '') {
                        'ragflow' => 'RAGFlow (RAG+LLM)',
                        'sample' => '样例（演示数据）',
                        default => '启发式（历史统计）',
                    };
                @endphp
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300">
                    来源：{{ $srcLabel }}
                </span>
            </div>

            {{-- 参数（可直接用于实验空间定义） --}}
            @if(!empty($suggestion['parameters']))
                <div>
                    <p class="mb-2 text-sm font-medium">建议参数（name + values）</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs uppercase text-slate-400">
                                <tr><th class="px-3 py-2">参数名</th><th class="px-3 py-2">取值</th></tr>
                            </thead>
                            <tbody>
                                @foreach($suggestion['parameters'] as $p)
                                    <tr class="border-t border-slate-100 dark:border-white/5">
                                        <td class="px-3 py-1.5 font-medium">{{ $p['name'] }}</td>
                                        <td class="px-3 py-1.5">{{ $p['values'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="button" wire:click="applyToForm"
                        class="mt-3 rounded-lg bg-edbo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-edbo-500">
                        应用到实验空间定义 →
                    </button>
                </div>
            @endif

            {{-- 参数空间建议 --}}
            @if(!empty($suggestion['parameter_space']))
                <div>
                    <p class="mb-2 text-sm font-medium">参数空间建议</p>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        @foreach($suggestion['parameter_space'] as $ps)
                            <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                <p class="text-sm font-semibold">{{ $ps['name'] }}</p>
                                <p class="mt-1 text-sm text-edbo-700 dark:text-edbo-300">{{ $ps['recommendation'] }}</p>
                                @if(!empty($ps['reason']))
                                    <p class="mt-1 text-xs text-slate-400">依据：{{ $ps['reason'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 候选参数组 --}}
            @if(!empty($suggestion['recommended_sets']))
                <div>
                    <p class="mb-2 text-sm font-medium">候选参数组</p>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        @foreach($suggestion['recommended_sets'] as $set)
                            <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                <p class="text-sm font-semibold">{{ $set['label'] }}</p>
                                <p class="mt-1 text-xs text-slate-400">预计：{{ $set['expected_yield'] ?? '—' }}</p>
                                <ul class="mt-2 space-y-0.5 text-sm">
                                    @foreach(($set['params'] ?? []) as $k => $v)
                                        <li><span class="text-slate-500">{{ $k }}</span> = <span class="font-medium">{{ $v }}</span></li>
                                    @endforeach
                                </ul>
                                @if(!empty($set['rationale']))
                                    <p class="mt-1 text-xs text-slate-400">{{ $set['rationale'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 总体说明 --}}
            @if(!empty($suggestion['rationale']))
                <div class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600 dark:bg-white/5 dark:text-slate-300">
                    {{ $suggestion['rationale'] }}
                </div>
            @endif
        </flux:card>
    @endif
</div>
