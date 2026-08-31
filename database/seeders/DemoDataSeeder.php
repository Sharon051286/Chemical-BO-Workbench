<?php

namespace Database\Seeders;

use App\Models\EdboRun;
use App\Services\EdboService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DemoDataSeeder
 * ---------------
 * 清空并重建一套干净的演示数据。
 *
 * 设计要点：
 *   - 直接调用 EdboService::runOptimization（与异步任务 RunEdboOptimization 同一入口），
 *     用「当前」已修复的 Python runner 真实跑出结果，而非手搓假 JSON，保证演示数据与
 *     线上引擎行为一致。
 *   - 三个代表性课题，覆盖核心能力：
 *       1) Ax 单目标（连续变量）—— 同一课题 2 个批次，演示「课题 + 批次」聚合
 *       2) Ax 多目标（yield + ee）含化学描述符 resolve（溶剂用真实 SMILES）—— 演示 Pareto 前沿 + 新接入的 resolve
 *       3) MNL 引擎（类别试剂）—— 演示离散选择 BO
 *   - 每个课题共用固定 task_uuid，便于 UI 按课题聚合展示。
 *   - 单条失败不影响其余（try/catch 后标记为 failed 并继续），最终汇总打印。
 */
class DemoDataSeeder extends Seeder
{
    /** 演示课题定义：task_uuid + 运行配置 */
    private array $scenarios = [
        // ── 课题 1：Ax 单目标（连续变量），2 个批次，演示聚合 ──
        [
            'task_uuid' => 'demo-ax-single',
            'config' => [
                'engine' => 'ax',
                'target' => 'yield',
                'objectives' => [['name' => 'yield', 'minimize' => false]],
                'batch_size' => 5,
                'acquisition_function' => 'EI',
                'init_method' => 'rand',
                'iterations' => 5,
                'training_iters' => 100,
                'seed' => 42,
                'parameters' => [
                    ['name' => '温度(℃)', 'values' => [80, 160], 'encoding' => 'numeric'],
                    ['name' => '时间(h)', 'values' => [1, 16], 'encoding' => 'numeric'],
                    ['name' => '催化剂(mol%)', 'values' => [1, 10], 'encoding' => 'numeric'],
                ],
                'prior_results' => [],
                'demo_mode' => true,
            ],
        ],
        [
            'task_uuid' => 'demo-ax-single',
            'config' => [
                'engine' => 'ax',
                'target' => 'yield',
                'objectives' => [['name' => 'yield', 'minimize' => false]],
                'batch_size' => 5,
                'acquisition_function' => 'EI',
                'init_method' => 'rand',
                'iterations' => 5,
                'training_iters' => 100,
                'seed' => 7,
                'parameters' => [
                    ['name' => '温度(℃)', 'values' => [80, 160], 'encoding' => 'numeric'],
                    ['name' => '时间(h)', 'values' => [1, 16], 'encoding' => 'numeric'],
                    ['name' => '催化剂(mol%)', 'values' => [1, 10], 'encoding' => 'numeric'],
                ],
                'prior_results' => [],
                'demo_mode' => true,
            ],
        ],
        // ── 课题 2：Ax 多目标（yield + ee）含 resolve 化学描述符（真实 SMILES）──
        [
            'task_uuid' => 'demo-ax-moo',
            'config' => [
                'engine' => 'ax',
                'target' => 'yield',
                'objectives' => [
                    ['name' => 'yield', 'minimize' => false],
                    ['name' => 'ee', 'minimize' => false],
                ],
                'batch_size' => 4,
                'acquisition_function' => 'EI',
                'init_method' => 'rand',
                'iterations' => 4,
                'training_iters' => 100,
                'seed' => 42,
                'parameters' => [
                    ['name' => '温度(℃)', 'values' => [70, 90, 80], 'encoding' => 'ohe'],
                    ['name' => '催化剂(mol%)', 'values' => [1, 10], 'encoding' => 'numeric'],
                    ['name' => '溶剂', 'values' => ['CCO', 'Cc1ccccc1', 'CO'], 'encoding' => 'resolve'],
                    ['name' => '时间(h)', 'values' => [10, 14, 12], 'encoding' => 'ohe'],
                ],
                'prior_results' => [],
                'demo_mode' => true,
            ],
        ],
        // ── 课题 3：MNL 引擎（类别试剂）演示离散选择 BO ──
        [
            'task_uuid' => 'demo-mnl',
            'config' => [
                'engine' => 'mnl',
                'target' => 'yield',
                'objectives' => [['name' => 'yield', 'minimize' => false]],
                'batch_size' => 3,
                'acquisition_function' => 'EI',
                'init_method' => 'rand',
                'iterations' => 2,
                'training_iters' => 50,
                'seed' => 42,
                'parameters' => [
                    ['name' => '催化剂', 'values' => ['CatA', 'CatB', 'CatC'], 'encoding' => 'ohe'],
                    ['name' => '溶剂', 'values' => ['乙醇', '甲苯', '乙腈'], 'encoding' => 'ohe'],
                    ['name' => '温度(℃)', 'values' => [80, 120], 'encoding' => 'numeric'],
                ],
                'prior_results' => [],
                'demo_mode' => true,
            ],
        ],
    ];

    public function run(): void
    {
        $service = new EdboService();
        $total = count($this->scenarios);
        $ok = 0;
        $failed = 0;

        $this->command?->info("开始生成 {$total} 条演示运行（直接调用当前 runner）...");

        foreach ($this->scenarios as $i => $sc) {
            $taskUuid = $sc['task_uuid'];
            $config = $sc['config'];
            $engine = $config['engine'];
            $uuid = (string) Str::uuid();
            $resultPath = 'edbo/runs/' . $uuid . '/results.json';

            // 先建记录（pending），与异步任务保持一致的结构
            $run = EdboRun::create([
                'uuid' => $uuid,
                'task_uuid' => $taskUuid,
                'engine' => $engine,
                'status' => 'pending',
                'config' => $config,
                'result_path' => $resultPath,
                'started_at' => now(),
            ]);

            try {
                $payload = $service->runOptimization($config, $uuid);
                $run->update([
                    'status' => 'completed',
                    'finished_at' => now(),
                ]);
                $ok++;
                $expN = count($payload['experiments'] ?? []);
                $recN = count($payload['recommended_experiments'] ?? []);
                $this->command?->info(
                    "  [{$ok}/{$total}] OK  task={$taskUuid} engine={$engine} experiments={$expN} recommended={$recN}"
                );
            } catch (RuntimeException $e) {
                $failed++;
                $run->update([
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 500),
                    'finished_at' => now(),
                ]);
                $this->command?->error("  [FAIL] task={$taskUuid} engine={$engine}: " . $e->getMessage());
            }
        }

        $this->command?->info("演示数据生成完成：成功 {$ok} / 失败 {$failed}（共 {$total}）");
    }
}
