<?php

namespace App\Livewire;

use App\Models\EdboRun;
use App\Services\EdboService;
use App\Services\PriorDataAdvisor;
use App\Services\RagFlowService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * PriorDataAssistant
 * ------------------
 * 「先验数据驱动」菜单的 Livewire 组件。职责：
 *   1. 语料管理：同步历史运行 / 上传文档 / 手动粘贴，统一存入本地语料库
 *   2. 把语料推送至 RAGFlow 知识库（需先 php artisan ragflow:setup）
 *   3. 生成建议：调用 PriorDataAdvisor（优先 RAGFlow，回退启发式）
 *   4. 把建议一键回填到「实验空间定义」表单（dispatch 事件给 EdboOptimizer）
 */
class PriorDataAssistant extends Component
{
    use WithFileUploads;

    public string $objective = '';
    public string $pasteText = '';
    public $upload = null;                 // Livewire 文件上传属性
    public array $corpus = [];             // 语料清单（来自 manifest）
    public ?array $suggestion = null;
    public bool $loading = false;
    public ?string $error = null;
    public ?string $info = null;
    public bool $ragflowReady = false;
    public ?string $ragflowHealth = null;   // 'ok' | 'down' | null（null=未检查）
    public array $ragflowDocs = [];         // 知识库文档列表（含解析状态）

    private function corpusRoot(): string
    {
        return config('ragflow.corpus_path', 'private/edbo/corpus');
    }

    public function mount(): void
    {
        $this->ragflowReady = app(RagFlowService::class)->isConfigured();
        $this->loadCorpus();

        // 首次访问且无语料时，自动填充样例数据（演示用），让界面开箱即有内容
        if (! Storage::disk('local')->exists($this->corpusRoot() . '/manifest.json')) {
            $this->seedSampleData(silent: true);
        }
    }

    public function loadCorpus(): void
    {
        $this->corpus = $this->readManifest();
    }

    // ------------------------------------------------------------------
    // 样例数据（演示用）
    // ------------------------------------------------------------------

    /**
     * 生成并填充一份样例先验数据（演示用）。
     * 写入 3 份语料（历史运行 markdown / 文献片段 / 实验笔记）与一份与语料
     * 一致的样例建议，使「先验数据驱动」界面开箱即有内容。首次访问由 mount
     * 自动调用；同时也暴露为按钮供手动（重新）加载。
     */
    public function seedSampleData(bool $silent = false): void
    {
        $disk = Storage::disk('local');
        $root = $this->corpusRoot();

        // 1) 历史运行（模拟 prior 系统历史运行汇总 markdown）
        $historical = <<<MD
# 系统历史运行汇总

## 运行 demo-2024-001（引擎 edbo，目标 yield）
- 参数：Temperature、Catalyst loading、Base、Solvent、Time
- 最优条件：Temperature=80，Catalyst loading=2，Base=K2CO3，Solvent=EtOH，Time=12（yield=92%）
- 实验明细：
  - Temperature=60，Catalyst loading=1，Base=K2CO3，Solvent=EtOH，Time=8，yield=71%
  - Temperature=80，Catalyst loading=2，Base=K2CO3，Solvent=EtOH，Time=12，yield=92%
  - Temperature=100，Catalyst loading=2，Base=K2CO3，Solvent=Toluene，Time=12，yield=85%
  - Temperature=80，Catalyst loading=3，Base=K2CO3，Solvent=EtOH，Time=12，yield=91%
  - Temperature=70，Catalyst loading=2，Base=Na2CO3，Solvent=EtOH，Time=12，yield=82%
  - Temperature=80，Catalyst loading=2，Base=K2CO3，Solvent=EtOH，Time=6，yield=78%

## 运行 demo-2024-002（引擎 edbo，目标 yield）
- 参数：Temperature、Catalyst loading、Base、Solvent、Time
- 最优条件：Temperature=75，Catalyst loading=1.5，Base=K2CO3，Solvent=EtOH，Time=12（yield=89%）
- 实验明细：
  - Temperature=75，Catalyst loading=1.5，Base=K2CO3，Solvent=EtOH，Time=12，yield=89%
  - Temperature=90，Catalyst loading=2，Base=K2CO3，Solvent=EtOH，Time=10，yield=88%
  - Temperature=80，Catalyst loading=1，Base=K2CO3，Solvent=EtOH，Time=12，yield=83%
MD;
        $relHist = $root . '/historical/historical_runs.md';
        $disk->makeDirectory(dirname($relHist));
        $disk->put($relHist, $historical);

        // 2) 文献片段
        $literature = <<<TXT
文献片段：《Pd 催化 Suzuki-Miyaura 偶联的条件优化》
摘要：在乙醇（EtOH）溶剂中，以 K2CO3 为碱、Pd 催化剂负载 2 mol% 时，80°C 反应 12 h 可获得最高产率（>90%）。进一步提高催化剂负载对产率提升有限，温度升高至 100°C 会加速副反应导致产率回落。甲苯等芳烃溶剂极性不足，传质受限，产率低于乙醇体系。
TXT;
        $relLit = $root . '/paste/lit_001.txt';
        $disk->makeDirectory(dirname($relLit));
        $disk->put($relLit, $literature);

        // 3) 实验笔记（手动粘贴风格）
        $notes = <<<TXT
实验笔记（操作员）：
- 乙醇作溶剂时产率明显高于甲苯，推测与碱在醇中的溶解度有关。
- 碱优选 K2CO3；换成 NaOH 体系更易乳化，后处理困难且产率略低。
- 催化剂负载超过 2 mol% 后产率几乎不再提升，降本可考虑 1.5 mol%。
- 反应时间 12 h 已接近平衡，延长至 24 h 无明显增益。
TXT;
        $relNotes = $root . '/paste/notes_001.txt';
        $disk->makeDirectory(dirname($relNotes));
        $disk->put($relNotes, $notes);

        $manifest = [
            [
                'id' => 'historical',
                'type' => 'historical',
                'name' => '系统历史运行（2 次 · 样例）',
                'path' => $relHist,
                'added_at' => now()->toDateTimeString(),
            ],
            [
                'id' => 'sample_lit',
                'type' => 'paste',
                'name' => '文献片段：Suzuki 偶联条件优化',
                'path' => $relLit,
                'added_at' => now()->toDateTimeString(),
            ],
            [
                'id' => 'sample_notes',
                'type' => 'paste',
                'name' => '实验笔记：溶剂/碱/催化剂观察',
                'path' => $relNotes,
                'added_at' => now()->toDateTimeString(),
            ],
        ];
        $this->writeManifest($manifest);
        $this->loadCorpus();

        // 默认优化目标
        if (trim($this->objective) === '') {
            $this->objective = '最大化产率，同时尽量降低催化剂成本与反应时间';
        }

        // 样例建议（与语料一致，演示用静态建议）
        $this->suggestion = $this->buildSampleSuggestion();
        $this->loading = false;
        $this->error = null;

        $this->info = $silent
            ? '已自动载入样例先验数据（演示）。'
            : '已加载样例语料（3 份）与建议（演示数据）。可点击「生成参数建议」用真实 RAGFlow 或启发式重新计算。';
    }

    /** 与样例语料一致的静态建议（仅用于演示填充）。 */
    private function buildSampleSuggestion(): array
    {
        return [
            'source' => 'sample',
            'parameters' => [
                ['name' => 'Temperature', 'values' => '70,80,90'],
                ['name' => 'Catalyst loading', 'values' => '1.5,2,2.5'],
                ['name' => 'Base', 'values' => 'K2CO3,Na2CO3'],
                ['name' => 'Solvent', 'values' => 'EtOH,Toluene'],
                ['name' => 'Time', 'values' => '10,12,14'],
            ],
            'parameter_space' => [
                ['name' => 'Temperature', 'recommendation' => '建议区间 70–90°C，高产点约 80°C', 'reason' => '历史与文献均显示 80°C 附近产率最高，>100°C 副反应增加。'],
                ['name' => 'Catalyst loading', 'recommendation' => '建议取值 1.5、2、2.5 mol%', 'reason' => '2 mol% 已近最优，>2 收益递减，可降本至 1.5。'],
                ['name' => 'Base', 'recommendation' => '建议取值 K2CO3、Na2CO3', 'reason' => 'K2CO3 在醇中溶解好、产率高；NaOH 易乳化。'],
                ['name' => 'Solvent', 'recommendation' => '建议关注 EtOH，备选 Toluene', 'reason' => '乙醇体系传质与溶解更优，产率高于甲苯。'],
                ['name' => 'Time', 'recommendation' => '建议区间 10–14 h，高产点约 12 h', 'reason' => '12 h 接近平衡，再延长无增益。'],
            ],
            'recommended_sets' => [
                ['label' => '方案A · 高产优先', 'params' => ['Temperature' => '80', 'Catalyst loading' => '2', 'Base' => 'K2CO3', 'Solvent' => 'EtOH', 'Time' => '12'], 'expected_yield' => '92%', 'rationale' => '历史最优条件，三份语料一致指向。'],
                ['label' => '方案B · 低成本', 'params' => ['Temperature' => '80', 'Catalyst loading' => '1.5', 'Base' => 'K2CO3', 'Solvent' => 'EtOH', 'Time' => '12'], 'expected_yield' => '89%', 'rationale' => '催化剂降至 1.5 mol% 仍保持高产，降低贵金属成本。'],
                ['label' => '方案C · 稳健', 'params' => ['Temperature' => '75', 'Catalyst loading' => '2', 'Base' => 'K2CO3', 'Solvent' => 'EtOH', 'Time' => '12'], 'expected_yield' => '90%', 'rationale' => '略降温抑制副反应，兼顾产率与安全性。'],
            ],
            'rationale' => '【样例数据】综合 3 份先验语料（系统历史运行、文献片段、实验笔记），一致指向 80°C、K2CO3/乙醇、2 mol% 催化剂为高产区；催化剂负载超过 2 mol% 收益递减，反应时间 12 h 接近平衡。以上为演示用静态建议；接入 RAGFlow 后可基于真实语料动态生成。',
            'raw' => '',
        ];
    }

    // ------------------------------------------------------------------
    // 语料管理
    // ------------------------------------------------------------------

    /** 同步历史运行结果为语料（覆盖式，仅保留一份） */
    public function syncHistoricalRuns(): void
    {
        $runs = EdboRun::where('status', 'completed')->orderByDesc('finished_at')->get();
        $md = $this->buildHistoricalMarkdown($runs);

        $disk = Storage::disk('local');
        $rel = $this->corpusRoot() . '/historical/historical_runs.md';
        $disk->makeDirectory(dirname($rel));
        $disk->put($rel, $md);

        $manifest = $this->readManifest();
        $manifest = array_values(array_filter($manifest, fn ($e) => $e['type'] !== 'historical'));
        $manifest[] = [
            'id' => 'historical',
            'type' => 'historical',
            'name' => '系统历史运行（' . $runs->count() . ' 次）',
            'path' => $rel,
            'added_at' => now()->toDateTimeString(),
        ];
        $this->writeManifest($manifest);
        $this->loadCorpus();
        $this->info = '已同步 ' . $runs->count() . ' 次历史运行到语料库。';
    }

    /** 添加手动粘贴文本 */
    public function addPaste(): void
    {
        $text = trim($this->pasteText);
        if ($text === '') {
            $this->error = '请先粘贴文本再添加。';

            return;
        }

        $id = 'paste_' . time();
        $rel = $this->corpusRoot() . '/paste/' . $id . '.txt';
        Storage::disk('local')->makeDirectory(dirname($rel));
        Storage::disk('local')->put($rel, $text);

        $manifest = $this->readManifest();
        $manifest[] = [
            'id' => $id,
            'type' => 'paste',
            'name' => '粘贴文本 ' . mb_substr(preg_replace('/\s+/', ' ', $text), 0, 20) . '…',
            'path' => $rel,
            'added_at' => now()->toDateTimeString(),
        ];
        $this->writeManifest($manifest);
        $this->pasteText = '';
        $this->loadCorpus();
        $this->info = '已添加粘贴文本到语料库。';
    }

    /** 上传文档 */
    public function uploadDocument(): void
    {
        if (! $this->upload instanceof TemporaryUploadedFile) {
            $this->error = '请选择要上传的文档。';

            return;
        }

        $id = 'upload_' . time();
        $rel = $this->corpusRoot() . '/uploads/' . $id . '_' . $this->upload->getClientOriginalName();
        Storage::disk('local')->makeDirectory(dirname($rel));
        // 复制到语料目录
        copy($this->upload->getRealPath(), Storage::disk('local')->path($rel));

        $manifest = $this->readManifest();
        $manifest[] = [
            'id' => $id,
            'type' => 'upload',
            'name' => $this->upload->getClientOriginalName(),
            'path' => $rel,
            'added_at' => now()->toDateTimeString(),
        ];
        $this->writeManifest($manifest);
        $this->upload = null;
        $this->loadCorpus();
        $this->info = '已上传文档：' . basename($rel);
    }

    /** 删除语料条目 */
    public function removeEntry(string $id): void
    {
        $manifest = $this->readManifest();
        $manifest = array_values(array_filter($manifest, function ($e) use ($id) {
            if ($e['id'] === $id) {
                if (Storage::disk('local')->exists($e['path'])) {
                    Storage::disk('local')->delete($e['path']);
                }

                return false;
            }

            return true;
        }));
        $this->writeManifest($manifest);
        $this->loadCorpus();
    }

    /** 把语料推送至 RAGFlow 知识库 */
    public function pushToRagFlow(): void
    {
        $rf = app(RagFlowService::class);
        if (! $rf->isConfigured()) {
            $this->error = 'RAGFlow 未配置：请先部署并在 .env 填入 RAGFLOW_API_KEY，然后运行 php artisan ragflow:setup。';

            return;
        }

        try {
            $datasetId = $rf->ensureDataset();
            $chatId = $rf->ensureChat($datasetId);
            $this->persistEnvIds($datasetId, $chatId);

            $manifest = $this->readManifest();
            if (empty($manifest)) {
                $this->error = '语料为空，请先同步历史运行或添加文档。';

                return;
            }

            $ok = 0;
            foreach ($manifest as $entry) {
                if (! Storage::disk('local')->exists($entry['path'])) {
                    continue;
                }
                $content = Storage::disk('local')->get($entry['path']);
                if ($entry['type'] === 'upload') {
                    // 上传原始文件（pdf/docx 等）
                    $abs = Storage::disk('local')->path($entry['path']);
                    if ($rf->uploadFile($abs, basename($entry['path']), $datasetId)) {
                        $ok++;
                    }
                } else {
                    if ($rf->uploadText($entry['name'], $content, $datasetId)) {
                        $ok++;
                    }
                }
            }

            $this->ragflowReady = true;
            $this->ragflowHealth = 'ok';
            $this->loadRagFlowDocuments($datasetId);
            $this->info = "已推送 {$ok} 个语料到 RAGFlow 知识库，请等待其解析完成后再生成建议。";
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    // ------------------------------------------------------------------
    // RAGFlow 知识库管理
    // ------------------------------------------------------------------

    /** 主动检查 RAGFlow 连通性，并拉取知识库文档 */
    public function checkConnection(): void
    {
        $rf = app(RagFlowService::class);
        if (! $rf->isConfigured()) {
            $this->ragflowHealth = null;
            $this->error = 'RAGFlow 未配置：请在 .env 设置 RAGFLOW_API_URL 与 RAGFLOW_API_KEY。';

            return;
        }
        $this->ragflowHealth = $rf->health() ? 'ok' : 'down';
        if ($this->ragflowHealth === 'ok') {
            $this->loadRagFlowDocuments();
            $this->info = 'RAGFlow 连接正常，已加载知识库文档。';
        } else {
            $this->error = '无法连接 RAGFlow（' . config('ragflow.api_url') . '），请确认服务已启动且 API Key 正确。';
        }
    }

    /** 拉取知识库文档列表（含解析状态 run），可指定 datasetId */
    public function loadRagFlowDocuments(?string $datasetId = null): void
    {
        $rf = app(RagFlowService::class);
        if (! $rf->isConfigured()) {
            return;
        }
        $datasetId = $datasetId ?? config('ragflow.dataset_id');
        if (empty($datasetId)) {
            $this->info = '知识库尚未初始化：请先运行 php artisan ragflow:setup。';

            return;
        }
        try {
            $docs = $rf->listDocuments($datasetId);
            $this->ragflowDocs = array_map(function ($d) {
                return [
                    'id' => $d['id'] ?? '',
                    'name' => $d['name'] ?? '(未命名)',
                    'size' => $d['size'] ?? 0,
                    'run' => (string) ($d['run'] ?? 'UNSTART'),
                    'chunk_count' => $d['chunk_count'] ?? 0,
                    'create_date' => $d['create_date'] ?? '',
                ];
            }, $docs);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->ragflowDocs = [];
        }
    }

    /** 刷新文档状态（轮询解析进度） */
    public function refreshRagFlow(): void
    {
        $this->loadRagFlowDocuments();
        if (empty($this->error)) {
            $this->info = '已刷新知识库文档状态。';
        }
    }

    /** 删除知识库中的文档 */
    public function deleteRagDoc(string $docId): void
    {
        $rf = app(RagFlowService::class);
        $datasetId = config('ragflow.dataset_id');
        if (empty($datasetId)) {
            $this->error = '知识库尚未初始化：请先运行 php artisan ragflow:setup。';

            return;
        }
        try {
            if ($rf->deleteDocuments($datasetId, [$docId])) {
                $this->info = '已删除文档。';
                $this->loadRagFlowDocuments($datasetId);
            } else {
                $this->error = '删除文档失败（RAGFlow 返回错误）。';
            }
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    // ------------------------------------------------------------------
    // 生成建议
    // ------------------------------------------------------------------

    public function generate(): void
    {
        $this->error = null;
        $this->loading = true;
        $this->suggestion = null;

        try {
            $corpusText = $this->aggregateCorpus();
            if ($corpusText === '') {
                $this->error = '语料为空：请先同步历史运行、上传或粘贴文档。';
                $this->loading = false;

                return;
            }

            $advisor = app(PriorDataAdvisor::class);
            $this->suggestion = $advisor->advise($this->objective, $corpusText, [
                'force_heuristic' => ! $this->ragflowReady,
            ]);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    /** 把建议一键回填到「实验空间定义」 */
    public function applyToForm(): void
    {
        if (empty($this->suggestion['parameters'])) {
            $this->error = '当前建议没有可应用的参数。';

            return;
        }

        $this->dispatch('priorApply', parameters: $this->suggestion['parameters']);
        $this->info = '已把建议参数发送到「实验空间定义」，请到该菜单查看。';
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    private function aggregateCorpus(): string
    {
        $manifest = $this->readManifest();
        $parts = [];
        foreach ($manifest as $entry) {
            if (! Storage::disk('local')->exists($entry['path'])) {
                continue;
            }
            $parts[] = "## {$entry['name']}\n" . Storage::disk('local')->get($entry['path']);
        }

        return implode("\n\n", $parts);
    }

    private function buildHistoricalMarkdown($runs): string
    {
        $out = "# 系统历史运行汇总\n\n";
        foreach ($runs as $run) {
            try {
                $result = app(EdboService::class)->getRunResult($run);
            } catch (\Throwable) {
                continue;
            }
            $target = $result['target'] ?? 'yield';
            $out .= "## 运行 {$run->uuid}（引擎 {$run->engine}，目标 {$target}）\n";
            $params = $result['parameter_names'] ?? [];
            if (! empty($params)) {
                $out .= '- 参数：' . implode('、', $params) . "\n";
            }
            if (! empty($result['best'])) {
                $best = $result['best'];
                $conds = [];
                foreach ($params as $p) {
                    if ($p === $target) {
                        continue;
                    }
                    $conds[] = "{$p}={$best[$p]}";
                }
                $out .= "- 最优条件：" . implode('，', $conds) . "（{$target}={$best[$target]}%）\n";
            }
            if (! empty($result['experiments'])) {
                $out .= "- 实验明细：\n";
                foreach (array_slice($result['experiments'], 0, 15) as $exp) {
                    $cells = [];
                    foreach ($params as $p) {
                        $cells[] = "{$p}={$exp[$p]}";
                    }
                    $cells[] = "{$target}={$exp[$target]}%";
                    $out .= "  - " . implode('，', $cells) . "\n";
                }
            }
            $out .= "\n";
        }

        return $out;
    }

    private function readManifest(): array
    {
        $rel = $this->corpusRoot() . '/manifest.json';
        if (! Storage::disk('local')->exists($rel)) {
            return [];
        }
        $data = json_decode(Storage::disk('local')->get($rel), true);

        return is_array($data) ? $data : [];
    }

    private function writeManifest(array $manifest): void
    {
        $rel = $this->corpusRoot() . '/manifest.json';
        Storage::disk('local')->makeDirectory(dirname($rel));
        Storage::disk('local')->put($rel, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** 把 dataset_id / chat_id 写回 .env（首次推送时确保后续可用） */
    private function persistEnvIds(string $datasetId, string $chatId): void
    {
        $env = base_path('.env');
        if (! file_exists($env)) {
            return;
        }
        $content = file_get_contents($env);
        $content = preg_replace('/^RAGFLOW_DATASET_ID=.*$/m', 'RAGFLOW_DATASET_ID=' . $datasetId, $content);
        $content = preg_replace('/^RAGFLOW_CHAT_ID=.*$/m', 'RAGFLOW_CHAT_ID=' . $chatId, $content);
        // 若原 .env 没有这两项（理论上不会，已在 .env.example 提供）
        if (! str_contains($content, 'RAGFLOW_DATASET_ID=')) {
            $content .= "\nRAGFLOW_DATASET_ID={$datasetId}\nRAGFLOW_CHAT_ID={$chatId}\n";
        }
        file_put_contents($env, $content);
    }

    public function render()
    {
        return view('edbo.prior');
    }
}
