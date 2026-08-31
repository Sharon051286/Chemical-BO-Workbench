<?php

namespace App\Services;

use App\Models\EdboRun;

/**
 * HeuristicAdvisor
 * ----------------
 * 无 LLM / RAGFlow 时的回退方案：直接分析历史运行的 results.json，
 * 用简单统计规则给出参数与参数空间建议。保证「先验数据驱动」模块
 * 在完全离线、未配置任何密钥时也能产出可用建议。
 *
 * 规则概要：
 *   - 数值参数：取历史 Top 实验附近的取值，给出更窄的区间
 *   - 类别参数：取历史中高频且高产的取值集合
 *   - 候选参数组：直接复用历史最优条件（Top-N）作为起点
 */
class HeuristicAdvisor
{
    /**
     * @param  array<int,EdboRun>  $runs  已完成的运行
     */
    public function advise(array $runs, string $objective = ''): array
    {
        $paramStats = [];   // name => ['numeric'=>bool, 'levels'=>[value=>sumYield/avgYield], 'all'=>[values]]
        $topSets = [];      // 历史最优条件候选

        foreach ($runs as $run) {
            $result = $this->safeResult($run);
            if ($result === null) {
                continue;
            }

            $target = $result['target'] ?? 'yield';
            $experiments = $result['experiments'] ?? [];
            if (empty($experiments)) {
                continue;
            }

            // 累计每个参数各取值的产率
            foreach ($experiments as $exp) {
                foreach ($result['parameter_names'] ?? array_keys($exp) as $pname) {
                    if ($pname === $target) {
                        continue;
                    }
                    $val = $exp[$pname] ?? null;
                    if ($val === null) {
                        continue;
                    }
                    $y = is_numeric($exp[$target] ?? null) ? (float) $exp[$target] : 0;
                    $paramStats[$pname] ??= ['numeric' => is_numeric($val), 'levels' => []];
                    $paramStats[$pname]['levels'][(string) $val] = ($paramStats[$pname]['levels'][(string) $val] ?? 0) + $y;
                }
            }

            // 记录该运行的最优条件
            $best = $result['best'] ?? null;
            if (is_array($best) && isset($best[$target])) {
                $topSets[] = [
                    'label'        => '历史最优#' . (count($topSets) + 1) . ' (' . ($run->engine ?? '?') . ')',
                    'params'       => $this->conditionToMap($best, $result['parameter_names'] ?? [], $target),
                    'expected_yield' => ($best[$target] ?? '?') . '%',
                    'rationale'    => '来自历史运行的最优评估条件。',
                ];
            }
        }

        // 生成参数空间建议（按平均产率挑前列）
        $parameterSpace = [];
        $parameters = [];
        foreach ($paramStats as $name => $stat) {
            $levels = $stat['levels'];
            if (empty($levels)) {
                continue;
            }
            arsort($levels); // 产率和从高到低
            $bestLevels = array_slice(array_keys($levels), 0, min(3, count($levels)), preserve_keys: true);

            if ($stat['numeric']) {
                $nums = array_map('floatval', array_keys($levels));
                sort($nums);
                $lo = $nums[0];
                $hi = end($nums);
                // 围绕高产取值收窄
                $topVals = array_map('floatval', $bestLevels);
                $suggested = [$lo, $hi];
                foreach ($topVals as $tv) {
                    $suggested[] = $tv;
                }
                $suggested = array_values(array_unique(array_map(fn ($v) => (string) $v, $suggested)));
                $parameters[] = ['name' => $name, 'values' => implode(',', $suggested)];
                $parameterSpace[] = [
                    'name' => $name,
                    'recommendation' => sprintf('建议区间 %.0f–%.0f，高产点约 %s', $lo, $hi, implode('/', array_map('strval', $topVals))),
                    'reason' => '基于历史产率分布，高产区集中在上述取值附近。',
                ];
            } else {
                $parameters[] = ['name' => $name, 'values' => implode(',', $bestLevels)];
                $parameterSpace[] = [
                    'name' => $name,
                    'recommendation' => '建议取值：' . implode(', ', $bestLevels),
                    'reason' => '历史中这些取值对应更高的平均产率。',
                ];
            }
        }

        usort($topSets, fn ($a, $b) => (float) str_replace('%', '', $b['expected_yield']) <=> (float) str_replace('%', '', $a['expected_yield']));
        $topSets = array_slice($topSets, 0, 3);

        $rationale = '【启发式模式】未连接 LLM/RAG，以下建议由历史运行统计得出'
            . ($objective !== '' ? '（目标：' . $objective . '）' : '')
            . '。每条参数的取值按其历史平均产率筛选，候选参数组直接复用历史最优条件。';

        return [
            'source' => 'heuristic',
            'parameters' => $parameters,
            'parameter_space' => $parameterSpace,
            'recommended_sets' => $topSets,
            'rationale' => $rationale,
            'raw' => '',
        ];
    }

    private function safeResult(EdboRun $run): ?array
    {
        try {
            return app(EdboService::class)->getRunResult($run);
        } catch (\Throwable) {
            return null;
        }
    }

    private function conditionToMap(array $best, array $paramNames, string $target): array
    {
        $map = [];
        foreach ($paramNames as $p) {
            if ($p === $target) {
                continue;
            }
            if (array_key_exists($p, $best)) {
                $map[$p] = $best[$p];
            }
        }

        return $map;
    }
}
