<?php

namespace App\Livewire;

use App\Jobs\RunEdboOptimization;
use App\Models\EdboRun;
use App\Services\EdboService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * EdboOptimizer
 * -------------
 * 工作台核心 Livewire 组件。
 *
 * 它承载「配置表单 → 调用 EDBO → 展示结果」的完整交互闭环，是团队最值得
 * 阅读学习的范例：展示了 Livewire 的响应式属性、表单校验、异步调用外部
 * 服务、以及错误边界处理。
 */
class EdboOptimizer extends Component
{
    protected $queryString = [
        'runUuid' => ['except' => ''],
    ];

    /** 实验参数列表：每项含 name 与 values（逗号分隔的原始输入） */
    public array $parameters = [];

    /** 演示版 / 正式版：演示版使用合成目标函数自动跑完全流程；正式版只基于真实实验数据推荐下一批。 */
    public bool $demoMode = true;

    /** 优化设置 */
    public string $engine = 'ax';
    /** 优化目标（可多个，构成多目标优化）。每项含 name 与 minimize。 */
    public array $objectives = [
        ['name' => 'yield', 'minimize' => false],
    ];

    /** 主目标（单目标展示兼容用，取 objectives[0] 的 name） */
    public string $target = 'yield';
    public int $batchSize = 5;
    public string $acquisition = 'EI';
    public string $initMethod = 'rand';
    public int $iterations = 12;

    /** 先验实验数据（CSV 文本，用户粘贴或上传） */
    public string $priorData = '';

    /** 正式版：在「推荐下一批实验」表格中为每行填入的目标值 */
    public array $recommendedInputs = [];

    /** 运行时状态 */
    public ?array $result = null;
    public ?string $error = null;
    public string $info = '';
    public bool $running = false;
    public bool $runQueued = false;
    public bool $runCompleted = false;
    public ?string $runUuid = null;
    public string $runStatus = 'idle';
    public array $health = [];
    public bool $healthLoading = true;
    public array $history = [];
    public ?string $selectedRunUuid = null;
    public ?array $selectedRun = null;

    /** 当前所在的优化课题（任务）UUID；为 null 表示尚未开启任何课题。
     *  正式版中，同一课题下的每次「保存并重新推荐」都是该课题的一个新批次。 */
    public ?string $taskUuid = null;

    /** 当前课题已累积的全部实验数据（含所有已完成的批次 + 历史 CSV 导入）。
     *  每次「保存并重新推荐」会把当前批次填入的结果并入此处，使下一批次
     *  基于完整累积数据建模，而非仅基于上一批次。 */
    public array $experimentLog = [];

    /** 结果分析图表数据 */
    public ?array $analysisData = null;
    public ?string $sliceParam = null;
    public ?string $contourParamX = null;
    public ?string $contourParamY = null;

    /** 顶部菜单导航状态：space=实验空间定义 / history=运行记录（选中运行后内嵌结果分析） */
    public string $activeMenu = 'space';
    /** 实验结果分析二级标签：best=最优条件 / convergence=收敛曲线 / analysis=结果分析 / all=全部评估实验 */
    public string $activeResultSection = 'best';

    /** 采集函数选项（与 EDBO 对齐） */
    public const ACQUISITIONS = [
        'EI'   => 'EI (期望改进)',
        'TS'   => 'TS (Thompson 采样)',
        'PI'   => 'PI (改进概率)',
        'UCB'  => 'UCB (置信上界)',
        'EI-TS'=> 'EI + TS 混合',
    ];

    /** 初始化方法选项 */
    public const INIT_METHODS = [
        'rand'   => '随机 (rand)',
        'kmedoids'=> 'k-medoids 聚类',
        'kmeans' => 'k-means 聚类',
    ];

    /** 优化引擎选项 */
    public const ENGINES = [
        'ax'   => 'Ax + BoTorch (Meta, 连续变量·更快收敛·多目标)',
        'mnl'  => 'MNL-BO (类别原生 surrogate, 多项 Logit)',
    ];

    public function mount(): void
    {
        // 默认载入示例，降低上手门槛
        // encoding：numeric=数值（默认） / ohe=One-Hot（类别推荐） / resolve=化学描述符（SMILES，需 rdkit）
        // 溶剂(SMILES) 用 resolve 编码：SMILES 经 edbo_chem(Mordred) 转为连续分子描述符进入高斯过程，
        // 使 BO 能感知分子结构相似性；候选集 = 下列溶剂库，故规模需 ≥ 评估预算（批量×(迭代+1)）以通过域规模守卫。
        $this->parameters = [
            ['name' => '温度(℃)',      'min' => 80,  'max' => 160, 'values' => '80,160', 'encoding' => 'numeric'],
            ['name' => '时间(h)',      'min' => 1,   'max' => 16,  'values' => '1,16',   'encoding' => 'numeric'],
            ['name' => '催化剂(mol%)', 'min' => 1,   'max' => 10,  'values' => '1,10',   'encoding' => 'numeric'],
            ['name' => '溶剂(SMILES)', 'min' => '',   'max' => '',  'encoding' => 'resolve', 'values' =>
                'O,CO,CCO,CCCO,CC(C)O,CCCCO,CC(C)CO,CC(C)(C)O,CCCCCCO,OC1CCCCC1,OCCO,OCC(O)CO,Oc1ccccc1,' .
                'OCC1=CC=CC=C1,CC(=O)C,CC#N,CCC#N,CN(C)C=O,CC(=O)N(C)C,O=C1CCCN1,CS(C)=O,O=S1(=O)CCCC1,' .
                'O=P(N(C)C)(N(C)C)N(C)C,CC(=O)O,CC=O,CC(=O)OC(=O)C,OC=O,CCC(=O)O,C1CCOC1,C1COCCO1,COCCOC,' .
                'COC(=O)OC,COC(C)(C)C,CCOC(C)(C)C,COCCOCCOC,COC(=O)C,Cc1ccccc1,Cc1ccc(C)cc1,CCc1ccccc1,' .
                'c1ccccc1,Clc1ccccc1,Fc1ccccc1,Brc1ccccc1,Nc1ccccc1,[O-][N+](=O)c1ccccc1,ClCCl,ClC(Cl)Cl,' .
                'ClC(Cl)(Cl)Cl,CCCCCC,CCCCCCC,C1CCCCC1,C1CCCC1,CCCCC,CC(C)CC(C)(C)C,CC1CCCCC1,CCOCC,' .
                'CCN(CC)CC,CCN(CC(C)C)CC(C)C,c1ccncc1,Cc1cccc(C)n1,CN(C)c1ccccc1,C1COCCN1,C1CCNCC1,CCOCCO,' .
                'C[N+](=O)[O-],OCC(F)(F)F,OC(C(F)(F)F)C(F)(F)F,O=C1CCCO1,O=C1CCCCO1,ClCCCl'],
        ];

        // Livewire 会把 query string 中的 runUuid 注入到组件属性中。
        // 如果页面刷新时仍有未完成的任务，则恢复状态并继续轮询。
        if ($this->runUuid) {
            $run = EdboRun::where('uuid', $this->runUuid)->first();
            if ($run) {
                $this->runStatus = $run->status;
                $this->runQueued = in_array($run->status, ['pending', 'processing']);
                $this->runCompleted = $run->status === 'completed';

                if ($run->status === 'failed') {
                    $this->error = $run->error ?? '优化任务失败，请查看日志。';
                }

                // 统一通过 selectRun 载入选中运行（含结果），使「运行记录」详情与结果分析联动
                $this->selectRun($run->uuid, app(EdboService::class));
                // 刷新带 runUuid 的页面时，直接落到「运行记录」并展示该次运行的分析
                $this->activeMenu = 'history';
            }
        }

        $this->loadHistory();

        // healthCheck 延迟到页面渲染后异步加载（wire:init），避免阻塞首屏渲染
    }

    /**
     * 异步加载环境健康状态（由前端 wire:init 触发）。
     */
    public function loadHealth(EdboService $edbo): void
    {
        $this->health = $edbo->healthCheck();
        $this->healthLoading = false;
    }

    /**
     * 添加一行参数。
     */
    public function addParameter(): void
    {
        $this->parameters[] = ['name' => '', 'min' => '', 'max' => '', 'values' => '', 'encoding' => 'numeric'];
    }

    /**
     * 删除指定参数行。
     */
    public function removeParameter(int $index): void
    {
        unset($this->parameters[$index]);
        $this->parameters = array_values($this->parameters);
    }

    /**
     * 添加一个优化目标（多目标优化）。
     */
    public function addObjective(): void
    {
        $this->objectives[] = ['name' => '', 'minimize' => false];
    }

    /**
     * 移除指定目标。
     */
    public function removeObjective(int $index): void
    {
        unset($this->objectives[$index]);
        $this->objectives = array_values($this->objectives);
        if (count($this->objectives) === 0) {
            $this->objectives = [['name' => 'yield', 'minimize' => false]];
        }
    }

    /**
     * 用一组预设参数替换当前表单（来自子组件的事件）。
     */
    public function loadPreset(array $preset): void
    {
        $this->parameters = $preset;
        $this->reset(['result', 'error']);
    }

    /**
     * 执行优化。表单校验通过后调用 EdboService。
     */
    public function runOptimization(EdboService $edbo): void
    {
        // 保留 $this->result 直到先验数据合并完成，因为行内输入需要关联到上一次推荐的实验条件。
        $this->reset(['error', 'info']);
        $this->running = true;

        // ---- 服务端校验：把「原始表单」解析成结构化配置 ----
        $parsed = $this->parseParameters();
        if (is_string($parsed)) {
            $this->error = $parsed;
            $this->running = false;
            return;
        }

        // ---- 解析优化目标（可多个，构建多目标优化）----
        $objectives = $this->parseObjectives();
        if (is_string($objectives)) {
            $this->error = $objectives;
            $this->running = false;
            return;
        }
        $this->objectives = $objectives;
        // 主目标取第一个，供下游单目标展示 / 历史记录兼容
        $this->target = $objectives[0]['name'];

        // ---- 多目标优化仅支持 Ax 引擎 ----
        if (count($objectives) > 1 && $this->engine !== 'ax') {
            $this->error = '多目标优化（定义了多个目标）仅支持 Ax 引擎。请选择「Ax + BoTorch」引擎，或只保留一个目标。';
            $this->running = false;
            return;
        }

        // ---- 解析先验数据（左侧 CSV + 推荐表格行内输入）----
        $csvResults = $this->parsePriorData($parsed);
        if (is_string($csvResults)) {
            $this->error = $csvResults;
            $this->running = false;
            return;
        }

        $inlineResults = $this->buildInlinePriorResults($parsed);

        // 是否首批次：无历史 result 即本课题的第一批（决定先验基线来源）。
        $isFirstBatch = ($this->result === null);

        // 正式版先验组装：
        //  - 首批次：以「历史 CSV 导入 + 当前批次填写的结果」作为初始累积；
        //  - 后续批次：直接复用已累积实验库（$this->experimentLog，其中已含 CSV 与历史批次），
        //    避免 CSV 被重复计入，并确保同一课题的各批次共享全部实验数据而非仅上一批次。
        if (! $this->demoMode) {
            if ($isFirstBatch) {
                $priorResults = array_merge($csvResults, $inlineResults);
            } else {
                // 给本次填写的批次结果打上递增批次号（runner 仅取参数+目标，标签会被忽略，
                // 但保留在 config.prior_results 中，使刷新/点击历史后重建累积库时不丢失批次归属）。
                $batchNo = $this->nextBatchNumber();
                $taggedInline = array_map(
                    fn ($r) => array_merge($r, ['batch' => $batchNo]),
                    $inlineResults
                );
                $priorResults = array_merge($this->experimentLog, $taggedInline);
            }
        } else {
            $priorResults = array_merge($csvResults, $inlineResults);
        }

        // ---- 正式版重新推荐时：必须录入真实目标值 ----
        // 首次运行正式版（无历史 result）允许空先验，用于生成空间填充初始设计；
        // 重新推荐时（已有 result）则要求至少提供一条带目标值的记录。
        if (! $this->demoMode && $this->result !== null) {
            $targetErr = $this->validatePriorTargets($priorResults);
            if ($targetErr !== null) {
                $this->error = $targetErr;
                $this->running = false;
                return;
            }
        }

        // ---- 域规模 vs 评估次数 守卫 ----
        // 域规模 vs 评估次数 守卫（仅针对离散参数）。
        // 连续数值参数（numeric 且取值为数值）不构成有限网格，不计入域组合数；
        // 若问题全部由连续变量组成，则域为连续空间、无有限组合数，此守卫直接跳过。
        // 该守卫原本为防止 EDBO 的 simulate() 采样超过离散域导致退化矩阵；
        // 连续域下 Ax 不存在此限制。先验数据点仅占用离散组合，连续参数下不扣除。
        $discrete = array_values(array_filter($parsed, function ($p) {
            $enc = $p['encoding'] ?? 'numeric';
            $isCat = false;
            foreach ($p['values'] as $v) {
                if (! is_numeric($v)) { $isCat = true; break; }
            }
            return in_array($enc, ['ohe', 'resolve'], true) || $isCat;
        }));
        if (count($discrete) > 0) {
            $combinations = 1;
            foreach ($discrete as $p) {
                $combinations *= count($p['values']);
            }
            $priorCount = count($priorResults);
            $maxEvals = $this->batchSize * ($this->iterations + 1) + $priorCount;
            if ($maxEvals > $combinations) {
                $this->error = sprintf(
                    '实验域共 %d 种离散组合，先验数据 %d 条 + 按批量(%d)与迭代(%d)约需 %d 次评估，超出域规模。请增加类别参数取值，或减少「批量大小 / 迭代轮数」。',
                    $combinations, $priorCount, $this->batchSize, $this->iterations, $maxEvals
                );
                $this->running = false;
                return;
            }
        }

        // 所有校验通过，清空旧结果以进入运行/排队状态
        $this->reset(['result']);

        $config = [
            'engine' => $this->engine,
            'target' => $this->target,
            'objectives' => $this->objectives,
            'batch_size' => $this->batchSize,
            'acquisition_function' => $this->acquisition,
            'init_method' => $this->initMethod,
            'iterations' => $this->iterations,
            'training_iters' => 100,
            'seed' => 42,
            'parameters' => $parsed,
            'prior_results' => $priorResults,
            'demo_mode' => $this->demoMode,
        ];

        try {
            // 正式版：把本次运行挂接到当前课题（taskUuid 为空则开启新课题）；
            // 演示版：每次运行各自独立成任务。
            $run = $edbo->dispatchOptimization($config, $this->demoMode ? null : $this->taskUuid);
            $this->runUuid = $run->uuid;

            // sync 队列模式下，dispatchOptimization 返回时任务已完成；
            // database 队列模式下，任务仍 pending，需要前端轮询。
            $freshRun = EdboRun::find($run->id);
            $this->runStatus = $freshRun->status;

            if ($freshRun->status === 'completed') {
                $this->result = $edbo->getRunResult($freshRun);
                $this->runCompleted = true;
                $this->runQueued = false;
                $this->generateAnalysis();
                $this->activeMenu = 'history';
                $this->activeResultSection = 'best';

                // 正式版：绑定课题并维护累积实验库，供下一轮推荐使用。
                if (! $this->demoMode) {
                    $this->taskUuid = $freshRun->task_uuid;
                    if ($isFirstBatch) {
                        // 首批次：累积库以 CSV 历史导入为基线（batch=0），此时无行内输入。
                        $this->experimentLog = array_map(
                            fn ($r) => array_merge(['batch' => 0], $r),
                            $csvResults
                        );
                    } elseif (! empty($inlineResults)) {
                        // 后续批次：给本次填写的批次结果打上递增批次号，并入累积库，
                        // 使同一课题的全部迭代实验都带正确的批次归属。
                        $batchNo = $this->nextBatchNumber();
                        $tagged = array_map(
                            fn ($r) => array_merge($r, ['batch' => $batchNo]),
                            $inlineResults
                        );
                        $this->experimentLog = array_merge($this->experimentLog, $tagged);
                    }
                }

                // 同步完成时也载入选中运行，使「运行记录」详情与分析联动
                $this->selectRun($freshRun->uuid, $edbo);
            } elseif ($freshRun->status === 'failed') {
                $this->error = $freshRun->error ?? '优化任务失败，请查看日志。';
                $this->runQueued = false;
            } else {
                $this->runQueued = true;
                $this->runCompleted = false;
            }

            $this->loadHistory();
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->runQueued = false;
            $this->runCompleted = false;
            $this->runStatus = 'failed';
        } finally {
            $this->running = false;
        }
    }

    public function checkRunStatus(EdboService $edbo): void
    {
        if ($this->runUuid === null) {
            return;
        }

        $run = EdboRun::where('uuid', $this->runUuid)->first();
        if (! $run) {
            return;
        }

        $this->runStatus = $run->status;

        if ($run->status === 'completed') {
            try {
                $this->result = $edbo->getRunResult($run);
                $this->runCompleted = true;
                $this->runQueued = false;
                $this->running = false;
                $this->generateAnalysis();
                $this->activeMenu = 'history';
                $this->activeResultSection = 'best';
                // 轮询完成时也载入选中运行，使「运行记录」详情与分析联动
                $this->selectRun($this->runUuid, $edbo);
            } catch (\RuntimeException $e) {
                $this->error = $e->getMessage();
                $this->runQueued = false;
                $this->runCompleted = false;
            }
        }

        if ($run->status === 'failed') {
            $this->error = $run->error ?? '优化任务失败，请查看日志。';
            $this->runQueued = false;
            $this->running = false;
        }

        if (in_array($run->status, ['pending', 'processing'])) {
            $this->runQueued = true;
        }

        $this->loadHistory();
    }

    public function selectRun(string $uuid, EdboService $edbo): void
    {
        $this->selectedRunUuid = $uuid;
        $run = EdboRun::where('uuid', $uuid)->first();
        if (! $run) {
            $this->selectedRun = null;
            return;
        }

        $this->selectedRun = [
            'uuid' => $run->uuid,
            'status' => $run->status,
            'engine' => $run->engine,
            'target' => $run->config['target'] ?? null,
            'batch_size' => $run->config['batch_size'] ?? null,
            'iterations' => $run->config['iterations'] ?? null,
            'acquisition_function' => $run->config['acquisition_function'] ?? null,
            'init_method' => $run->config['init_method'] ?? null,
            'parameters' => $run->config['parameters'] ?? [],
            'prior_results_count' => count($run->config['prior_results'] ?? []),
            'error' => $run->error,
            'started_at' => $run->started_at?->toDateTimeString(),
            'finished_at' => $run->finished_at?->toDateTimeString(),
            'created_at' => $run->created_at?->toDateTimeString(),
            'finished' => $run->finished_at !== null,
            'result' => null,
        ];

        if ($run->status === 'completed') {
            try {
                $this->selectedRun['result'] = $edbo->getRunResult($run);
                // 选中运行即作为「正在查看的运行」，详情与结果分析共用此结果
                $this->result = $this->selectedRun['result'];
                $this->resetRecommendedInputs();
                $this->generateAnalysis();
                // 选中运行后默认展示「最优条件」标签
                $this->activeResultSection = 'best';

                // 续接该运行所属课题：恢复课题 UUID 与已累积的实验数据，
                // 使「保存并重新推荐」能在该课题下继续追加批次（刷新/点击历史后亦有效）。
                // 注意：从课题「最新一次已完成运行」重建累积库，而不是当前选中的旧批次，
                // 否则选中旧批次会把累积库覆盖成部分数据，导致重新推荐从错误状态续接。
                if (! $this->demoMode) {
                    $this->taskUuid = $run->task_uuid;
                    $latest = EdboRun::where('task_uuid', $run->task_uuid)
                        ->where('status', 'completed')
                        ->orderBy('created_at', 'desc')
                        ->first();
                    $this->experimentLog = $latest ? ($latest->config['prior_results'] ?? []) : [];
                }
            } catch (\RuntimeException $e) {
                $this->selectedRun['result_error'] = $e->getMessage();
            }
        }
    }

    /**
     * 开启一个全新的优化课题（任务）。
     * 清空当前课题上下文与累积实验数据，回到实验空间定义页，
     * 供用户在更换参数空间 / 目标时从零开始，而不与旧课题的批次混在一起。
     */
    public function newTask(): void
    {
        $this->taskUuid = null;
        $this->experimentLog = [];
        $this->reset([
            'result', 'error', 'info', 'runUuid', 'runStatus',
            'runQueued', 'runCompleted', 'selectedRunUuid', 'selectedRun',
            'priorData', 'recommendedInputs',
        ]);
        $this->running = false;
        $this->activeMenu = 'space';
        $this->loadHistory();
    }

    /**
     * 计算下一个批次号：取累积实验库中已出现的最大批次号 + 1。
     * 历史 CSV 导入固定为 batch=0，正式版每个评估批次依次为 1, 2, 3 ...。
     */
    private function nextBatchNumber(): int
    {
        $max = 0;
        foreach ($this->experimentLog as $row) {
            $b = (int) ($row['batch'] ?? 0);
            if ($b > $max) {
                $max = $b;
            }
        }
        return $max + 1;
    }

    private function loadHistory(): void
    {
        $runs = EdboRun::orderBy('created_at', 'desc')->take(50)->get();

        // 按课题（task_uuid）分组；旧数据无 task_uuid 时退回以自身 uuid 分组，
        // 保证每条旧运行仍以独立「单批次课题」呈现，不被吞并。
        $groups = [];
        foreach ($runs as $run) {
            $key = $run->task_uuid ?? $run->uuid;
            $groups[$key]['runs'][] = $run;
        }

        $tasks = [];
        foreach ($groups as $key => $group) {
            // 组内按时间升序编号批次（1, 2, 3 ...）
            $sorted = collect($group['runs'])->sortBy('created_at')->values();
            $taskRuns = [];
            foreach ($sorted as $idx => $run) {
                $taskRuns[] = [
                    'uuid' => $run->uuid,
                    'short_uuid' => substr($run->uuid, 0, 8),
                    'status' => $run->status,
                    'engine' => $run->engine,
                    'target' => $run->config['target'] ?? null,
                    'batch_size' => $run->config['batch_size'] ?? null,
                    'iterations' => $run->config['iterations'] ?? null,
                    'created_at' => $run->created_at?->format('Y-m-d H:i:s'),
                    'batch' => $idx + 1,
                ];
            }
            $first = $sorted->first();
            $last = $sorted->last();
            $tasks[] = [
                'task_uuid' => $key,
                'short_task' => substr($key, 0, 8),
                'engine' => $first->engine,
                'target' => $first->config['target'] ?? null,
                'batch_size' => $first->config['batch_size'] ?? null,
                'iterations' => $first->config['iterations'] ?? null,
                'batch_count' => count($taskRuns),
                'created_at' => $last->created_at?->format('Y-m-d H:i:s'),
                'runs' => $taskRuns,
            ];
        }

        // 课题按最近一次运行时间倒序排列
        usort($tasks, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        $this->history = $tasks;
    }

    /**
     * 解析先验实验数据（CSV 文本 -> 结构化数组）。
     *
     * 用户在前端粘贴 CSV 格式数据：第一行为表头（参数名 + 目标列），
     * 后续每行为一条实验记录。例如：
     *   温度(℃),时间(h),催化剂(mol%),yield
     *   80,1,1,45.2
     *   120,4,5,78.6
     *
     * 返回数组表示成功（可为空数组），返回字符串表示错误信息。
     */
    private function parsePriorData(array $parsedParameters): array|string
    {
        $raw = trim($this->priorData);
        if ($raw === '') {
            return [];
        }

        // 按行拆分，支持 \n 和 \r\n
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn($l) => $l !== '');
        if (count($lines) < 2) {
            return '先验数据至少需要表头行 + 1 行数据。';
        }

        // 解析表头
        $headers = array_map('trim', explode(',', $lines[0]));
        $paramNames = array_map(fn($p) => $p['name'], $parsedParameters);

        // 校验表头：必须包含所有参数名 + 目标列
        foreach ($paramNames as $name) {
            if (!in_array($name, $headers)) {
                return "先验数据表头中缺少参数列「{$name}」。";
            }
        }
        foreach ($this->objectives as $obj) {
            $objName = $obj['name'];
            if (!in_array($objName, $headers)) {
                return "先验数据表头中缺少目标列「{$objName}」。";
            }
        }

        // 解析数据行
        $results = [];
        $headerIndex = array_flip($headers);
        for ($i = 1; $i < count($lines); $i++) {
            $cells = array_map('trim', explode(',', $lines[$i]));
            if (count($cells) !== count($headers)) {
                return '第 ' . ($i + 1) . ' 行的列数与表头不匹配（期望 ' . count($headers) . ' 列，实际 ' . count($cells) . ' 列）。';
            }

            $row = [];
            foreach ($headers as $h) {
                $row[$h] = $cells[$headerIndex[$h]];
            }
            $results[] = $row;
        }

        return $results;
    }

    /**
     * 校验正式版先验数据中的目标值是否已填写且为有效数字。
     * 返回 null 表示通过，返回字符串表示错误信息。
     */
    private function validatePriorTargets(array $priorResults): ?string
    {
        if (empty($priorResults)) {
            return '正式版必须录入真实实验结果。请先在左侧「真实实验结果录入」粘贴历史数据，或在下方「推荐的下一批实验」表格右侧填写至少一条带目标值的记录。';
        }

        foreach ($priorResults as $i => $row) {
            foreach ($this->objectives as $obj) {
                $name = $obj['name'];
                $val = trim((string) ($row[$name] ?? ''));
                if ($val === '' || ! is_numeric($val)) {
                    return '第 ' . ($i + 1) . ' 条先验数据的「' . $name . '」目标值无效（必须为数字）。请在表格右侧或 CSV 区域填写完整后再保存并重新推荐。';
                }
            }
        }

        return null;
    }

    /**
     * 根据当前 result 初始化推荐表格的行内输入框。
     * 每当结果就绪或切换历史运行时调用，保证输入框与推荐行一一对应。
     */
    private function resetRecommendedInputs(): void
    {
        $this->recommendedInputs = [];
        if (! $this->result || empty($this->result['recommended_experiments'])) {
            return;
        }

        $objectives = $this->result['objectives'] ?? [['name' => $this->result['target'] ?? 'yield']];
        foreach ($this->result['recommended_experiments'] as $i => $rec) {
            $inputs = [];
            foreach ($objectives as $obj) {
                $inputs[$obj['name']] = '';
            }
            $this->recommendedInputs[$i] = $inputs;
        }
    }

    /**
     * 把用户在「推荐下一批实验」表格中填写的目标值，合并为先验数据行。
     * 完全留空的行会被跳过；至少填了一个目标值的行会被纳入，由 validatePriorTargets 校验完整性。
     */
    private function buildInlinePriorResults(array $parsedParameters): array
    {
        if ($this->demoMode || empty($this->recommendedInputs) || empty($this->result['recommended_experiments'])) {
            return [];
        }

        $paramNames = array_map(fn ($p) => $p['name'], $parsedParameters);
        $objectiveNames = array_map(fn ($o) => $o['name'], $this->objectives);
        $rows = [];

        foreach ($this->result['recommended_experiments'] as $i => $rec) {
            $inputs = $this->recommendedInputs[$i] ?? [];
            $anyFilled = false;
            foreach ($objectiveNames as $name) {
                if (isset($inputs[$name]) && trim((string) $inputs[$name]) !== '') {
                    $anyFilled = true;
                    break;
                }
            }
            if (! $anyFilled) {
                continue;
            }

            $row = [];
            foreach ($paramNames as $name) {
                $row[$name] = (string) ($rec[$name] ?? '');
            }
            foreach ($objectiveNames as $name) {
                $row[$name] = (string) ($inputs[$name] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 解析参数表单为 EDBO 可消费的数组。
     * 返回数组表示成功，返回字符串表示错误信息。
     *
     * @return array|string
     */
    private function parseParameters(): array|string
    {
        $out = [];
        $seen = [];

        foreach ($this->parameters as $i => $param) {
            $name = trim((string) ($param['name'] ?? ''));
            if ($name === '') {
                return "第 " . ($i + 1) . " 个参数名称为空，请补全。";
            }
            if (isset($seen[$name])) {
                return "参数名「{$name}」重复，请使用唯一名称。";
            }
            $seen[$name] = true;

            $userEnc = trim((string) ($param['encoding'] ?? 'numeric'));
            if ($userEnc === '') {
                $userEnc = 'numeric';
            }

            if ($userEnc === 'numeric') {
                // 连续区间：读取最小值 / 最大值
                $minRaw = trim((string) ($param['min'] ?? ''));
                $maxRaw = trim((string) ($param['max'] ?? ''));
                // 兼容「先验建议回填」：只有 values 字符串、无独立 min/max 的情况
                if (($minRaw === '' || $maxRaw === '') && isset($param['values'])) {
                    $parts = array_filter(array_map('trim', explode(',', (string) $param['values'])), fn ($v) => $v !== '');
                    if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                        $minRaw = $parts[0];
                        $maxRaw = $parts[1];
                    }
                }
                if ($minRaw === '' || $maxRaw === '' || ! is_numeric($minRaw) || ! is_numeric($maxRaw)) {
                    return "参数「{$name}」请填写连续区间的最小值与最大值（均为数字）。";
                }
                $lo = $minRaw + 0;
                $hi = $maxRaw + 0;
                if ($lo >= $hi) {
                    return "参数「{$name}」的最小值必须小于最大值。";
                }
                $out[] = ['name' => $name, 'values' => [$lo, $hi], 'encoding' => 'numeric'];
            } else {
                // ohe / resolve：离散取值列表（英文逗号分隔）
                $raw = trim((string) ($param['values'] ?? ''));
                if ($raw === '') {
                    return "参数「{$name}」取值为空，请填写离散取值（英文逗号分隔）。";
                }
                // 按逗号拆分，去除空白；尝试数值化，失败则保持字符串（类别型）
                $values = array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== '');
                if (count($values) < 2) {
                    return "参数「{$name}」至少需要 2 个取值。";
                }
                $typed = [];
                foreach ($values as $v) {
                    $typed[] = is_numeric($v) ? ($v + 0) : $v;
                }
                $out[] = ['name' => $name, 'values' => array_values($typed), 'encoding' => $userEnc];
            }
        }

        if (count($out) === 0) {
            return '请至少定义一个实验参数。';
        }
        if (count($out) > 8) {
            return '参数数量过多（>8），实验域组合数将爆炸式增长，请精简。';
        }

        // 估算离散组合规模，防止笛卡尔积过大（连续数值参数不构成有限网格，不计入）
        $combinations = 1;
        foreach ($out as $p) {
            $enc = $p['encoding'] ?? 'numeric';
            $isCat = false;
            foreach ($p['values'] as $v) {
                if (! is_numeric($v)) { $isCat = true; break; }
            }
            if (in_array($enc, ['ohe', 'resolve'], true) || $isCat) {
                $combinations *= count($p['values']);
            }
        }
        if ($combinations > 200000) {
            return "实验域离散组合数过大（{$combinations}），请减少类别参数取值或参数数量。";
        }

        return $out;
    }

    /**
     * 解析优化目标列表（多目标优化）。
     * 每个目标含 name（指标列名）与 minimize（是否最小化）。
     * 返回数组表示成功，返回字符串表示错误信息。
     *
     * @return array|string
     */
    private function parseObjectives(): array|string
    {
        $objectives = $this->objectives;
        if (! is_array($objectives) || count($objectives) === 0) {
            return '请至少定义一个优化目标。';
        }

        $clean = [];
        $seen = [];
        foreach ($objectives as $i => $obj) {
            $name = trim((string) ($obj['name'] ?? ''));
            if ($name === '') {
                return "第 " . ($i + 1) . " 个目标名称为空，请补全。";
            }
            if (isset($seen[$name])) {
                return "目标名称「{$name}」重复，请使用唯一名称。";
            }
            $seen[$name] = true;
            // 兼容 Livewire select 传入的 "0"/"1" 字符串与布尔/整数
            $raw = $obj['minimize'] ?? false;
            $minimize = ($raw === true || $raw === 1 || $raw === '1');
            $clean[] = ['name' => $name, 'minimize' => $minimize];
        }

        if (count($clean) > 4) {
            return '目标数量过多（>4），多目标 Pareto 前沿将难以解释，请精简。';
        }

        return $clean;
    }

    // ===================================================================
    //  结果分析图表数据计算
    // ===================================================================

    /**
     * 生成结果分析数据（重要性、交叉验证、切片图、等高线图）。
     * 在结果就绪后自动调用。
     */
    /**
     * 本课题「全部评估实验」的单一数据真相（聚合池）。
     * 正式版：历史 CSV 导入（batch=0）+ 各已评估批次（batch=1,2,…）累积库，
     *        并并入当前正在填写的最新批次（尚未重新推荐、未持久化的部分）；
     *        这样即使刚填完最新一批、尚未点「保存并重新推荐」，分析与全部实验也已反映完整数据。
     * 演示版：直接返回 runner 合成的 experiments。
     */
    public function getTaskExperimentsProperty(): array
    {
        if ($this->demoMode) {
            return $this->result['experiments'] ?? [];
        }
        if (! is_array($this->result)) {
            return $this->experimentLog;
        }
        $pool = $this->experimentLog;
        $parsed = $this->parseParameters();
        if (is_array($parsed)) {
            foreach ($this->buildInlinePriorResults($parsed) as $row) {
                $pool[] = array_merge($row, ['batch' => $this->nextBatchNumber()]);
            }
        }
        return $pool;
    }

    /**
     * 本课题「最优条件」：基于聚合池计算（取目标值最大者），而非单批次 runner 结果。
     * 演示版回退到 runner 返回的 best。
     */
    public function getTaskBestProperty(): ?array
    {
        if ($this->demoMode) {
            return $this->result['best'] ?? null;
        }
        $pool = $this->getTaskExperimentsProperty();
        if (empty($pool)) {
            return null;
        }
        $target = $this->result['target'] ?? 'yield';
        $best = null;
        $bestVal = null;
        foreach ($pool as $row) {
            $v = $row[$target] ?? null;
            if ($v === null || ! is_numeric($v)) {
                continue;
            }
            $fv = (float) $v;
            if ($bestVal === null || $fv > $bestVal) {
                $bestVal = $fv;
                $best = $row;
            }
        }
        if ($best === null) {
            return null;
        }
        $best[$target] = $bestVal;
        return $best;
    }

    /**
     * 分析所使用的数据源：正式版用聚合池，演示版用 runner 合成实验。
     */
    private function analysisExperiments(): array
    {
        if ($this->demoMode) {
            return $this->result['experiments'] ?? [];
        }
        return $this->getTaskExperimentsProperty();
    }

    public function generateAnalysis(): void
    {
        if (! $this->result) {
            $this->analysisData = null;
            return;
        }

        $experiments = $this->analysisExperiments();
        $paramNames = $this->result['parameter_names'] ?? [];
        $target = $this->result['target'] ?? 'yield';

        if (empty($experiments) || empty($paramNames)) {
            $this->analysisData = null;
            return;
        }

        // 默认选择第一个参数用于 slice plot
        if ($this->sliceParam === null || ! in_array($this->sliceParam, $paramNames)) {
            $this->sliceParam = $paramNames[0];
        }
        // 默认选择前两个参数用于 contour map
        if ($this->contourParamX === null || ! in_array($this->contourParamX, $paramNames)) {
            $this->contourParamX = $paramNames[0];
        }
        if ($this->contourParamY === null || ! in_array($this->contourParamY, $paramNames) || $this->contourParamY === $this->contourParamX) {
            $others = array_values(array_diff($paramNames, [$this->contourParamX]));
            $this->contourParamY = $others[0] ?? $paramNames[0];
        }

        $this->analysisData = [
            'importance' => $this->computeImportance($experiments, $paramNames, $target),
            'cross_validation' => $this->computeCrossValidation($experiments, $paramNames, $target),
            'slice_plot' => $this->computeSlicePlot($experiments, $paramNames, $target, $this->sliceParam),
            'contour' => $this->computeContour($experiments, $paramNames, $target, $this->contourParamX, $this->contourParamY),
        ];
    }

    /**
     * 前端切换 slice plot 参数时触发。
     */
    public function updateSlicePlot(): void
    {
        if ($this->result && $this->sliceParam) {
            $this->analysisData['slice_plot'] = $this->computeSlicePlot(
                $this->analysisExperiments(),
                $this->result['parameter_names'],
                $this->result['target'],
                $this->sliceParam
            );
        }
    }

    /**
     * 前端切换 contour map 参数时触发。
     */
    public function updateContourMap(): void
    {
        if ($this->result && $this->contourParamX && $this->contourParamY) {
            $this->analysisData['contour'] = $this->computeContour(
                $this->analysisExperiments(),
                $this->result['parameter_names'],
                $this->result['target'],
                $this->contourParamX,
                $this->contourParamY
            );
        }
    }

    /**
     * 极差分析：计算各参数对目标的贡献重要性。
     * 对每个参数按取值分组，计算组内 yield 均值，极差越大说明该参数越重要。
     */
    private function computeImportance(array $experiments, array $paramNames, string $target): array
    {
        $importance = [];

        foreach ($paramNames as $name) {
            $groups = [];
            foreach ($experiments as $exp) {
                $val = $exp[$name];
                $key = is_numeric($val) ? (string) (float) $val : (string) $val;
                if (! isset($groups[$key])) {
                    $groups[$key] = [];
                }
                $groups[$key][] = (float) $exp[$target];
            }

            $groupMeans = [];
            $groupLabels = [];
            foreach ($groups as $key => $values) {
                $groupMeans[] = array_sum($values) / count($values);
                $groupLabels[] = $key;
            }

            $range = max($groupMeans) - min($groupMeans);
            $importance[] = [
                'name' => $name,
                'range' => round($range, 2),
                'group_means' => array_map(fn ($m) => round($m, 2), $groupMeans),
                'group_labels' => $groupLabels,
            ];
        }

        // 归一化到百分比
        $maxRange = max(array_column($importance, 'range'));
        if ($maxRange > 0) {
            foreach ($importance as &$imp) {
                $imp['normalized'] = round(($imp['range'] / $maxRange) * 100, 1);
            }
        } else {
            foreach ($importance as &$imp) {
                $imp['normalized'] = 0;
            }
        }

        // 按重要性排序（从大到小）
        usort($importance, fn ($a, $b) => $b['range'] <=> $a['range']);

        return $importance;
    }

    /**
     * 交叉验证：用二次多项式回归做留一交叉验证（LOO-CV）。
     * 数值参数用原值（含平方项）；类别参数做 One-Hot（drop-first）展开，
     * 使交叉验证真正覆盖类别变量，而非像旧版那样直接丢弃它们。
     * 返回每个实验点的预测值 vs 真实值，以及 R²。
     */
    private function computeCrossValidation(array $experiments, array $paramNames, string $target): array
    {
        // 区分数值参数与类别参数（类别参数在 runner 中已还原为字符串取值）
        $numericParams = [];
        $categoricalParams = [];
        foreach ($paramNames as $name) {
            if (! isset($experiments[0][$name])) {
                continue;
            }
            if (is_numeric($experiments[0][$name])) {
                $numericParams[] = $name;
            } else {
                $categoricalParams[] = $name;
            }
        }

        if ((count($numericParams) + count($categoricalParams)) === 0 || count($experiments) < 5) {
            return ['points' => [], 'r_squared' => 0, 'available' => false, 'method' => ''];
        }

        // 标准化数值参数到 [0, 1]
        $ranges = [];
        foreach ($numericParams as $name) {
            $vals = array_map(fn ($e) => (float) $e[$name], $experiments);
            $min = min($vals);
            $max = max($vals);
            $ranges[$name] = ['min' => $min, 'range' => ($max - $min) ?: 1];
        }

        // 类别参数取值集合（用于 One-Hot 展开，drop-first 避免与截距共线）
        $catLevels = [];
        foreach ($categoricalParams as $name) {
            $levels = array_values(array_unique(array_map(fn ($e) => (string) $e[$name], $experiments)));
            sort($levels);
            $catLevels[$name] = $levels;
        }

        // 构建设计矩阵行：[1, 数值..., 数值²..., 类别One-Hot(drop-first)...]
        $buildRow = function ($exp) use ($numericParams, $ranges, $categoricalParams, $catLevels) {
            $row = [1.0];
            $norm = [];
            foreach ($numericParams as $name) {
                $v = ((float) $exp[$name] - $ranges[$name]['min']) / $ranges[$name]['range'];
                $norm[$name] = $v;
                $row[] = $v;
            }
            foreach ($numericParams as $name) {
                $row[] = $norm[$name] * $norm[$name];
            }
            foreach ($categoricalParams as $name) {
                $levels = $catLevels[$name];
                $val = (string) $exp[$name];
                // drop-first：从第二个取值起各生成一个 0/1 特征
                for ($i = 1; $i < count($levels); $i++) {
                    $row[] = ($val === $levels[$i]) ? 1.0 : 0.0;
                }
            }
            return $row;
        };

        $y = array_map(fn ($e) => (float) $e[$target], $experiments);
        $n = count($experiments);

        $cvPoints = [];
        $useLOO = $n <= 80;

        if ($useLOO) {
            for ($i = 0; $i < $n; $i++) {
                $trainX = [];
                $trainY = [];
                for ($j = 0; $j < $n; $j++) {
                    if ($j === $i) {
                        continue;
                    }
                    $trainX[] = $buildRow($experiments[$j]);
                    $trainY[] = $y[$j];
                }
                $beta = $this->leastSquares($trainX, $trainY);
                if ($beta === null) {
                    // 矩阵奇异，回退到全数据拟合
                    $allX = [];
                    for ($j = 0; $j < $n; $j++) {
                        $allX[] = $buildRow($experiments[$j]);
                    }
                    $beta = $this->leastSquares($allX, $y);
                    if ($beta === null) {
                        return ['points' => [], 'r_squared' => 0, 'available' => false, 'method' => ''];
                    }
                    for ($k = 0; $k < $n; $k++) {
                        $pred = $this->dotProduct($buildRow($experiments[$k]), $beta);
                        $cvPoints[] = ['actual' => round($y[$k], 2), 'predicted' => round($pred, 2)];
                    }
                    break;
                }
                $pred = $this->dotProduct($buildRow($experiments[$i]), $beta);
                $cvPoints[] = ['actual' => round($y[$i], 2), 'predicted' => round($pred, 2)];
            }
        } else {
            $allX = [];
            for ($j = 0; $j < $n; $j++) {
                $allX[] = $buildRow($experiments[$j]);
            }
            $beta = $this->leastSquares($allX, $y);
            if ($beta === null) {
                return ['points' => [], 'r_squared' => 0, 'available' => false, 'method' => ''];
            }
            for ($k = 0; $k < $n; $k++) {
                $pred = $this->dotProduct($buildRow($experiments[$k]), $beta);
                $cvPoints[] = ['actual' => round($y[$k], 2), 'predicted' => round($pred, 2)];
            }
        }

        // 计算 R²
        $meanY = array_sum($y) / count($y);
        $ssTot = 0;
        $ssRes = 0;
        foreach ($cvPoints as $p) {
            $ssTot += ($p['actual'] - $meanY) ** 2;
            $ssRes += ($p['actual'] - $p['predicted']) ** 2;
        }
        $rSquared = $ssTot > 0 ? round(1 - $ssRes / $ssTot, 3) : 0;

        return [
            'points' => $cvPoints,
            'r_squared' => $rSquared,
            'available' => true,
            'method' => $useLOO ? 'LOO-CV（含类别 One-Hot）' : '全数据拟合（含类别 One-Hot）',
        ];
    }

    /**
     * Slice plot：固定其他参数为最优值，遍历选定参数取值，用 IDW 插值估计 yield。
     */
    private function computeSlicePlot(array $experiments, array $paramNames, string $target, string $selectedParam): array
    {
        // 获取选定参数的唯一取值（排序）
        $uniqueVals = array_values(array_unique(array_map(fn ($e) => $e[$selectedParam], $experiments)));
        usort($uniqueVals, fn ($a, $b) => is_numeric($a) && is_numeric($b) ? (float) $a <=> (float) $b : strcmp((string) $a, (string) $b));

        // 最优实验点
        $bestExp = null;
        $bestYield = -PHP_FLOAT_MAX;
        foreach ($experiments as $exp) {
            if ((float) $exp[$target] > $bestYield) {
                $bestYield = (float) $exp[$target];
                $bestExp = $exp;
            }
        }

        // 计算各参数的归一化范围
        $ranges = [];
        foreach ($paramNames as $name) {
            $vals = array_map(fn ($e) => is_numeric($e[$name]) ? (float) $e[$name] : 0, $experiments);
            $ranges[$name] = ['min' => min($vals), 'range' => (max($vals) - min($vals)) ?: 1];
        }

        $points = [];
        foreach ($uniqueVals as $val) {
            $idealPoint = [];
            foreach ($paramNames as $name) {
                $idealPoint[$name] = ($name === $selectedParam) ? $val : $bestExp[$name];
            }

            // IDW 插值
            $weightedSum = 0;
            $weightSum = 0;
            foreach ($experiments as $exp) {
                $dist = 0;
                foreach ($paramNames as $name) {
                    if (is_numeric($exp[$name]) && is_numeric($idealPoint[$name])) {
                        $d = ((float) $exp[$name] - (float) $idealPoint[$name]) / $ranges[$name]['range'];
                        $dist += $d * $d;
                    } elseif ((string) $exp[$name] !== (string) $idealPoint[$name]) {
                        $dist += 1;
                    }
                }
                $dist = sqrt($dist);
                if ($dist < 0.001) {
                    $weightedSum = (float) $exp[$target];
                    $weightSum = 1;
                    break;
                }
                $weight = 1 / ($dist ** 3);
                $weightedSum += $weight * (float) $exp[$target];
                $weightSum += $weight;
            }
            $estimatedYield = $weightSum > 0 ? $weightedSum / $weightSum : 0;

            $points[] = [
                'x' => is_numeric($val) ? (float) $val : null,
                'label' => (string) $val,
                'yield' => round($estimatedYield, 2),
            ];
        }

        $fixedParams = array_values(array_filter($paramNames, fn ($p) => $p !== $selectedParam));
        $fixedValues = [];
        foreach ($fixedParams as $p) {
            $fixedValues[$p] = $bestExp[$p];
        }

        return [
            'param' => $selectedParam,
            'points' => $points,
            'fixed_params' => $fixedParams,
            'fixed_values' => $fixedValues,
        ];
    }

    /**
     * Contour map：固定其他参数为最优值，在两个选定参数的网格上用 IDW 插值估计 yield。
     */
    private function computeContour(array $experiments, array $paramNames, string $target, string $paramX, string $paramY): array
    {
        // 最优实验点
        $bestExp = null;
        $bestYield = -PHP_FLOAT_MAX;
        foreach ($experiments as $exp) {
            if ((float) $exp[$target] > $bestYield) {
                $bestYield = (float) $exp[$target];
                $bestExp = $exp;
            }
        }

        // 参数范围
        $valsX = array_map(fn ($e) => (float) $e[$paramX], $experiments);
        $valsY = array_map(fn ($e) => (float) $e[$paramY], $experiments);
        $minX = min($valsX);
        $maxX = max($valsX);
        $minY = min($valsY);
        $maxY = max($valsY);
        $rangeX = ($maxX - $minX) ?: 1;
        $rangeY = ($maxY - $minY) ?: 1;

        // 各参数归一化范围
        $ranges = [];
        foreach ($paramNames as $name) {
            $vals = array_map(fn ($e) => is_numeric($e[$name]) ? (float) $e[$name] : 0, $experiments);
            $ranges[$name] = ['min' => min($vals), 'range' => (max($vals) - min($vals)) ?: 1];
        }

        $gridSize = 24;
        $grid = [];
        $allValues = [];

        for ($i = 0; $i < $gridSize; $i++) {
            $row = [];
            $x = $minX + ($i / ($gridSize - 1)) * $rangeX;
            for ($j = 0; $j < $gridSize; $j++) {
                $y = $minY + ($j / ($gridSize - 1)) * $rangeY;

                $idealPoint = [];
                foreach ($paramNames as $name) {
                    if ($name === $paramX) {
                        $idealPoint[$name] = $x;
                    } elseif ($name === $paramY) {
                        $idealPoint[$name] = $y;
                    } else {
                        $idealPoint[$name] = $bestExp[$name];
                    }
                }

                // IDW 插值
                $weightedSum = 0;
                $weightSum = 0;
                foreach ($experiments as $exp) {
                    $dist = 0;
                    foreach ($paramNames as $name) {
                        if (is_numeric($exp[$name]) && is_numeric($idealPoint[$name])) {
                            $d = ((float) $exp[$name] - (float) $idealPoint[$name]) / $ranges[$name]['range'];
                            $dist += $d * $d;
                        } elseif ((string) $exp[$name] !== (string) $idealPoint[$name]) {
                            $dist += 1;
                        }
                    }
                    $dist = sqrt($dist);
                    if ($dist < 0.001) {
                        $weightedSum = (float) $exp[$target];
                        $weightSum = 1;
                        break;
                    }
                    $weight = 1 / ($dist ** 3);
                    $weightedSum += $weight * (float) $exp[$target];
                    $weightSum += $weight;
                }
                $estimatedYield = $weightSum > 0 ? $weightedSum / $weightSum : 0;
                $val = round($estimatedYield, 1);
                $row[] = $val;
                $allValues[] = $val;
            }
            $grid[] = $row;
        }

        $fixedParams = array_values(array_filter($paramNames, fn ($p) => $p !== $paramX && $p !== $paramY));
        $fixedValues = [];
        foreach ($fixedParams as $p) {
            $fixedValues[$p] = $bestExp[$p];
        }

        return [
            'param_x' => $paramX,
            'param_y' => $paramY,
            'min_x' => $minX,
            'max_x' => $maxX,
            'min_y' => $minY,
            'max_y' => $maxY,
            'grid' => $grid,
            'grid_size' => $gridSize,
            'fixed_params' => $fixedParams,
            'fixed_values' => $fixedValues,
            'min_yield' => min($allValues),
            'max_yield' => max($allValues),
        ];
    }

    /**
     * 最小二乘法：求解 β = (X'X)^{-1} X'y。
     * 用高斯消元法解正规方程组。
     */
    private function leastSquares(array $X, array $y): ?array
    {
        $n = count($X);
        if ($n === 0) {
            return null;
        }
        $k = count($X[0]);

        // XtX (k x k)
        $XtX = array_fill(0, $k, array_fill(0, $k, 0.0));
        for ($i = 0; $i < $k; $i++) {
            for ($j = 0; $j < $k; $j++) {
                $sum = 0.0;
                for ($m = 0; $m < $n; $m++) {
                    $sum += $X[$m][$i] * $X[$m][$j];
                }
                $XtX[$i][$j] = $sum;
            }
        }

        // Xty (k vector)
        $Xty = array_fill(0, $k, 0.0);
        for ($i = 0; $i < $k; $i++) {
            $sum = 0.0;
            for ($m = 0; $m < $n; $m++) {
                $sum += $X[$m][$i] * $y[$m];
            }
            $Xty[$i] = $sum;
        }

        // 增广矩阵 [XtX | Xty]
        $aug = array_fill(0, $k, array_fill(0, $k + 1, 0.0));
        for ($i = 0; $i < $k; $i++) {
            for ($j = 0; $j < $k; $j++) {
                $aug[$i][$j] = $XtX[$i][$j];
            }
            $aug[$i][$k] = $Xty[$i];
        }

        // 高斯消元（带部分主元选取）
        for ($col = 0; $col < $k; $col++) {
            $maxRow = $col;
            $maxVal = abs($aug[$col][$col]);
            for ($row = $col + 1; $row < $k; $row++) {
                if (abs($aug[$row][$col]) > $maxVal) {
                    $maxVal = abs($aug[$row][$col]);
                    $maxRow = $row;
                }
            }
            if ($maxVal < 1e-12) {
                return null; // 奇异矩阵
            }
            if ($maxRow !== $col) {
                $tmp = $aug[$col];
                $aug[$col] = $aug[$maxRow];
                $aug[$maxRow] = $tmp;
            }
            for ($row = $col + 1; $row < $k; $row++) {
                $factor = $aug[$row][$col] / $aug[$col][$col];
                for ($j = $col; $j <= $k; $j++) {
                    $aug[$row][$j] -= $factor * $aug[$col][$j];
                }
            }
        }

        // 回代
        $beta = array_fill(0, $k, 0.0);
        for ($i = $k - 1; $i >= 0; $i--) {
            $sum = $aug[$i][$k];
            for ($j = $i + 1; $j < $k; $j++) {
                $sum -= $aug[$i][$j] * $beta[$j];
            }
            $beta[$i] = $sum / $aug[$i][$i];
        }

        return $beta;
    }

    /**
     * 点积。
     */
    private function dotProduct(array $a, array $b): float
    {
        $sum = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }

    /**
     * 重置当前正式版推荐表格的行内输入框。
     * 现在目标值直接在「推荐的下一批实验」表格右侧填写，此方法用于清空已填内容或重新初始化模板。
     */
    public function seedPriorFromRecommended(): void
    {
        $recommended = $this->result['recommended_experiments'] ?? [];
        if (empty($recommended)) {
            $this->error = '当前没有推荐实验可生成录入模板。请先运行正式版生成推荐。';
            return;
        }

        $this->resetRecommendedInputs();
        $this->info = '请在「推荐的下一批实验」表格右侧填写真实目标值，然后点击「保存并重新推荐」。';
    }

    /**
     * 接收「先验数据驱动」组件发来的建议参数，回填到实验空间定义表单。
     * 由 PriorDataAssistant 通过 dispatch('priorApply', ...) 触发。
     */
    #[On('priorApply')]
    public function applyPriorSuggestion(array $parameters, ?string $target = null): void
    {
        // 仅接受符合 name/values 结构的条目，丢弃脏数据
        $clean = [];
        foreach ($parameters as $p) {
            if (! is_array($p) || ! isset($p['name']) || ! isset($p['values'])) {
                continue;
            }
            // values 可能是数组（如 [80,160]）或字符串（如 "80,160"），统一归一为 token 列表
            $rawVals = $p['values'];
            if (is_array($rawVals)) {
                $parts = array_values(array_filter(array_map('trim', $rawVals), fn ($v) => $v !== ''));
            } else {
                $parts = array_values(array_filter(array_map('trim', explode(',', (string) $rawVals)), fn ($v) => $v !== ''));
            }
            $name = (string) $p['name'];

            // 恰好两个数字 -> 连续区间，回填为 min/max；否则按离散（One-Hot）处理
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $clean[] = [
                    'name'    => $name,
                    'min'     => $parts[0],
                    'max'     => $parts[1],
                    'values'  => implode(',', $parts),
                    'encoding' => 'numeric',
                ];
            } else {
                $clean[] = [
                    'name'    => $name,
                    'min'     => '',
                    'max'     => '',
                    'values'  => implode(',', $parts),
                    'encoding' => 'ohe',
                ];
            }
        }

        if (! empty($clean)) {
            $this->parameters = $clean;
        }
        if ($target !== null && $target !== '') {
            $this->target = $target;
        }
        $this->activeMenu = 'space';
    }

    public function render()
    {
        // 通过 view()->layout() 注入布局：避免在组件视图里直接写 <x-layouts.app> 包裹层，
        // 否则 <body> 会出现多个直接子元素，触发 Livewire 的
        // "Multiple root elements detected" 报错。布局统一由组件控制，职责更清晰。
        return view('edbo.index')
            ->layout('components.layouts.app', [
                'title' => 'EDBO 优化工作台',
            ]);
    }
}
