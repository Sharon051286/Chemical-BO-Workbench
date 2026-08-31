<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EdboRun;
use App\Services\EdboService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdboController extends Controller
{
    public function health(EdboService $edbo): JsonResponse
    {
        return response()->json($edbo->healthCheck());
    }

    public function projects(EdboService $edbo): JsonResponse
    {
        $runs = EdboRun::orderBy('created_at', 'desc')->take(80)->get();
        $groups = [];
        foreach ($runs as $run) {
            $key = $run->task_uuid ?? $run->uuid;
            $groups[$key]['runs'][] = $run;
        }

        $projects = [];
        foreach ($groups as $key => $group) {
            $sorted = collect($group['runs'])->sortBy('created_at')->values();
            $first = $sorted->first();
            $last = $sorted->last();
            $config = is_array($last->config) ? $last->config : ($first->config ?? []);
            if (! empty($config['demo_mode'])) {
                continue;
            }
            $best = '—';
            if ($last->status === 'completed') {
                try {
                    $result = $edbo->getRunResult($last);
                    $target = $result['target'] ?? ($config['target'] ?? 'yield');
                    $bestVal = $result['best'][$target] ?? null;
                    $best = $bestVal !== null ? $bestVal.' %' : '—';
                } catch (\Throwable) {
                    $best = '—';
                }
            }

            $status = match ($last->status) {
                'completed' => (! empty($config['demo_mode'])) ? '已完成' : '待录入',
                'failed' => '已完成',
                default => '运行中',
            };

            $expCount = count($config['experiment_log'] ?? []);
            if ($expCount === 0) {
                $expCount = count($config['prior_results'] ?? []);
            }

            $projects[] = [
                'id' => substr((string) $key, 0, 8),
                'task_uuid' => $key,
                'name' => $config['project_name'] ?? ('课题 '.substr((string) $key, 0, 8)),
                'engine' => strtoupper((string) ($first->engine ?? 'ax')),
                'objective' => $config['target'] ?? 'yield',
                'batches' => $sorted->count(),
                'experiments' => $expCount,
                'best' => $best,
                'updated' => optional($last->created_at)->format('Y-m-d H:i'),
                'status' => $status,
                'latest_uuid' => $last->uuid,
                'demo_mode' => (bool) ($config['demo_mode'] ?? true),
            ];
        }

        return response()->json(['projects' => $projects]);
    }

    public function run(Request $request, EdboService $edbo): JsonResponse
    {
        $parsed = $this->parseParameters($request->input('parameters', []));
        if (is_string($parsed)) {
            return response()->json(['ok' => false, 'error' => $parsed], 422);
        }

        $objectives = $this->parseObjectives($request);
        if (is_string($objectives)) {
            return response()->json(['ok' => false, 'error' => $objectives], 422);
        }

        $engine = (string) $request->input('engine', 'ax');
        if (count($objectives) > 1 && $engine !== 'ax') {
            return response()->json(['ok' => false, 'error' => '多目标优化仅支持 Ax 引擎。'], 422);
        }

        $demoMode = $request->boolean('demo_mode', true);
        $batchSize = max(1, (int) $request->input('batch_size', 5));
        $iterations = max(1, (int) $request->input('iterations', 1));
        $taskUuid = $request->input('task_uuid');
        $taskUuid = is_string($taskUuid) && $taskUuid !== '' ? $taskUuid : null;

        $csvResults = $this->parsePriorCsv((string) $request->input('prior_data', ''), $parsed, $objectives);
        if (is_string($csvResults)) {
            return response()->json(['ok' => false, 'error' => $csvResults], 422);
        }

        $inlineResults = $request->input('prior_results', []);
        if (! is_array($inlineResults)) {
            $inlineResults = [];
        }

        $previousLog = [];
        $usedCombinations = [];
        if (! $demoMode && $taskUuid) {
            $previousLog = $this->loadExperimentLog($taskUuid);
            $usedCombinations = $this->loadUsedCombinations($taskUuid, $parsed);
        }
        $isFirstBatch = $previousLog === [];

        if (! $demoMode) {
            if ($isFirstBatch) {
                $priorResults = array_merge($csvResults, $inlineResults);
            } else {
                $priorResults = array_merge($previousLog, $inlineResults);
            }
            $priorResults = $this->uniqueByParams($priorResults, $parsed);
        } else {
            $priorResults = $this->uniqueByParams(array_merge($csvResults, $inlineResults), $parsed);
        }

        $usedCombinations = $this->paramOnlyRows(
            $this->uniqueByParams(array_merge($usedCombinations, $priorResults), $parsed),
            $parsed
        );

        if (! $demoMode && ! $isFirstBatch) {
            $targetErr = $this->validatePriorTargets($priorResults, $objectives);
            if ($targetErr !== null) {
                return response()->json(['ok' => false, 'error' => $targetErr], 422);
            }
        }

        $guard = $this->domainGuard($parsed, $priorResults, $batchSize, $iterations);
        if ($guard !== null) {
            return response()->json(['ok' => false, 'error' => $guard], 422);
        }

        $config = [
            'engine' => $engine,
            'target' => $objectives[0]['name'],
            'objectives' => $objectives,
            'batch_size' => $batchSize,
            'acquisition_function' => (string) $request->input('acquisition', 'EI'),
            'init_method' => (string) $request->input('init_method', 'rand'),
            'iterations' => $iterations,
            'training_iters' => 100,
            'seed' => 42,
            'parameters' => $parsed,
            'prior_results' => $priorResults,
            'used_combinations' => $usedCombinations,
            'demo_mode' => $demoMode,
            'project_name' => $request->input('project_name'),
        ];

        try {
            $run = $edbo->dispatchOptimization($config, $demoMode ? null : $taskUuid);
            $fresh = EdboRun::find($run->id);
            $payload = [
                'ok' => true,
                'uuid' => $run->uuid,
                'task_uuid' => $fresh->task_uuid,
                'status' => $fresh->status,
                'experiment_log' => [],
            ];

            if ($fresh->status === 'completed') {
                $payload['result'] = $edbo->getRunResult($fresh);
                $log = $previousLog;
                if (! $demoMode) {
                    if ($isFirstBatch && $csvResults !== []) {
                        $log = array_map(fn ($r) => array_merge(['batch' => 0], $r), $csvResults);
                    }
                    if ($inlineResults !== []) {
                        $batchNo = max(1, $this->nextBatchNumber($log));
                        $tagged = array_map(fn ($r) => array_merge($r, ['batch' => $batchNo]), $inlineResults);
                        $log = $this->uniqueByParams(array_merge($log, $tagged), $parsed);
                    }
                    $cfg = $fresh->config ?? [];
                    $cfg['experiment_log'] = $log;
                    $next = [];
                    if (is_array($payload['result'] ?? null) && is_array($payload['result']['predicted_next'] ?? null)) {
                        $next = $this->paramOnlyRows($payload['result']['predicted_next'], $parsed);
                    }
                    $cfg['used_combinations'] = $this->paramOnlyRows(
                        $this->uniqueByParams(array_merge($usedCombinations, $log, $next), $parsed),
                        $parsed
                    );
                    $fresh->update(['config' => $cfg]);
                }
                $payload['experiment_log'] = $log;
            } elseif ($fresh->status === 'failed') {
                $payload['ok'] = false;
                $payload['error'] = $fresh->error ?? '优化任务失败';
            }

            return response()->json($payload);
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function show(string $uuid, EdboService $edbo): JsonResponse
    {
        $run = EdboRun::where('uuid', $uuid)->first();
        if (! $run) {
            return response()->json(['ok' => false, 'error' => '运行不存在'], 404);
        }

        $payload = [
            'ok' => true,
            'uuid' => $run->uuid,
            'task_uuid' => $run->task_uuid,
            'status' => $run->status,
            'engine' => $run->engine,
            'config' => $run->config,
            'experiment_log' => $run->config['experiment_log'] ?? [],
            'started_at' => $run->started_at?->toDateTimeString(),
            'finished_at' => $run->finished_at?->toDateTimeString(),
            'error' => $run->error,
        ];
        if ($run->status === 'completed') {
            try {
                $payload['result'] = $edbo->getRunResult($run);
            } catch (\RuntimeException $e) {
                $payload['result_error'] = $e->getMessage();
            }
        }

        return response()->json($payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     * @return array<int, array<string, mixed>>|string
     */
    private function parseParameters(array $parameters): array|string
    {
        $out = [];
        $seen = [];
        foreach ($parameters as $i => $param) {
            $name = trim((string) ($param['name'] ?? ''));
            if ($name === '') {
                return '第 '.($i + 1).' 个参数名称为空，请补全。';
            }
            if (isset($seen[$name])) {
                return "参数名「{$name}」重复。";
            }
            $seen[$name] = true;
            $userEnc = trim((string) ($param['encoding'] ?? 'numeric')) ?: 'numeric';
            $raw = trim((string) ($param['values'] ?? ''));
            if ($userEnc === 'numeric') {
                $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
                if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                    return "参数「{$name}」请填写连续区间「最小值, 最大值」。";
                }
                $lo = $parts[0] + 0;
                $hi = $parts[1] + 0;
                if ($lo >= $hi) {
                    return "参数「{$name}」的最小值必须小于最大值。";
                }
                $out[] = ['name' => $name, 'values' => [$lo, $hi], 'encoding' => 'numeric'];
            } else {
                $values = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
                if (count($values) < 2) {
                    return "参数「{$name}」至少需要 2 个取值。";
                }
                $typed = [];
                foreach ($values as $v) {
                    $typed[] = is_numeric($v) ? $v + 0 : $v;
                }
                $out[] = ['name' => $name, 'values' => $typed, 'encoding' => $userEnc];
            }
        }

        if ($out === []) {
            return '请至少定义一个实验参数。';
        }

        return $out;
    }

    /**
     * @return array<int, array{name: string, minimize: bool}>|string
     */
    private function parseObjectives(Request $request): array|string
    {
        $raw = $request->input('objectives');
        if (is_array($raw) && $raw !== []) {
            $out = [];
            foreach ($raw as $obj) {
                $name = trim((string) ($obj['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $out[] = [
                    'name' => $name,
                    'minimize' => filter_var($obj['minimize'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }
            if ($out === []) {
                return '请至少定义一个优化目标。';
            }

            return $out;
        }

        $objectives = [['name' => 'yield', 'minimize' => false]];
        if ($request->boolean('multi_objective')) {
            $second = trim((string) $request->input('second_objective', 'cost'));
            $objectives[] = ['name' => ($second !== '' ? $second : 'cost'), 'minimize' => true];
        }

        return $objectives;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @param  array<int, array{name: string, minimize: bool}>  $objectives
     * @return array<int, array<string, mixed>>|string
     */
    private function parsePriorCsv(string $raw, array $parsed, array $objectives): array|string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
        if (count($lines) < 2) {
            return '先验数据至少需要表头行 + 1 行数据。';
        }
        $headers = array_map('trim', explode(',', $lines[0]));
        $paramNames = array_map(fn ($p) => $p['name'], $parsed);
        foreach ($paramNames as $name) {
            if (! in_array($name, $headers, true)) {
                return "先验数据表头中缺少参数列「{$name}」。";
            }
        }
        foreach ($objectives as $obj) {
            if (! in_array($obj['name'], $headers, true)) {
                return "先验数据表头中缺少目标列「{$obj['name']}」。";
            }
        }
        $results = [];
        $headerIndex = array_flip($headers);
        for ($i = 1; $i < count($lines); $i++) {
            $cells = array_map('trim', explode(',', $lines[$i]));
            if (count($cells) !== count($headers)) {
                return '第 '.($i + 1).' 行的列数与表头不匹配。';
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
     * @param  array<int, array<string, mixed>>  $priorResults
     * @param  array<int, array{name: string, minimize: bool}>  $objectives
     */
    private function validatePriorTargets(array $priorResults, array $objectives): ?string
    {
        if ($priorResults === []) {
            return '正式版重新推荐需要至少一条带真实目标值的实验记录。';
        }
        foreach ($priorResults as $i => $row) {
            foreach ($objectives as $obj) {
                $name = $obj['name'];
                if (! isset($row[$name]) || $row[$name] === '' || ! is_numeric($row[$name])) {
                    return '第 '.($i + 1).' 条记录缺少有效目标「'.$name.'」。';
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @param  array<int, mixed>  $priorResults
     */
    private function domainGuard(array $parsed, array $priorResults, int $batchSize, int $iterations): ?string
    {
        $discrete = array_values(array_filter($parsed, function ($p) {
            $enc = $p['encoding'] ?? 'numeric';
            $isCat = false;
            foreach ($p['values'] as $v) {
                if (! is_numeric($v)) {
                    $isCat = true;
                    break;
                }
            }

            return in_array($enc, ['ohe', 'resolve'], true) || $isCat;
        }));
        if ($discrete === []) {
            return null;
        }
        $combinations = 1;
        foreach ($discrete as $p) {
            $combinations *= count($p['values']);
        }
        $priorCount = count($priorResults);
        $maxEvals = $batchSize * ($iterations + 1) + $priorCount;
        if ($maxEvals > $combinations) {
            return sprintf(
                '实验域共 %d 种离散组合，先验数据 %d 条 + 按批量(%d)与迭代(%d)约需 %d 次评估，超出域规模。请增加类别参数取值，或减少「批量大小 / 迭代轮数」。',
                $combinations,
                $priorCount,
                $batchSize,
                $iterations,
                $maxEvals
            );
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadExperimentLog(string $taskUuid): array
    {
        $latest = EdboRun::where('task_uuid', $taskUuid)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();
        if (! $latest) {
            return [];
        }
        $log = $latest->config['experiment_log'] ?? null;
        if (is_array($log)) {
            return $log;
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function loadUsedCombinations(string $taskUuid, array $parsed): array
    {
        $latest = EdboRun::where('task_uuid', $taskUuid)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();
        if (! $latest) {
            return [];
        }
        $cfg = is_array($latest->config) ? $latest->config : [];
        $rows = [];
        foreach (['used_combinations', 'experiment_log', 'prior_results'] as $key) {
            if (is_array($cfg[$key] ?? null)) {
                $rows = array_merge($rows, $cfg[$key]);
            }
        }
        $resultPath = $latest->result_path;
        if (is_string($resultPath) && $resultPath !== '') {
            try {
                $payload = json_decode(\Illuminate\Support\Facades\Storage::disk('local')->get($resultPath), true);
                if (is_array($payload['predicted_next'] ?? null)) {
                    $rows = array_merge($rows, $payload['predicted_next']);
                }
            } catch (\Throwable) {
            }
        }

        return $this->paramOnlyRows($this->uniqueByParams($rows, $parsed), $parsed);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function paramOnlyRows(array $rows, array $parsed): array
    {
        $names = array_map(fn ($p) => $p['name'], $parsed);
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = [];
            foreach ($names as $name) {
                if (array_key_exists($name, $row)) {
                    $item[$name] = $row[$name];
                }
            }
            if ($item !== []) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array<string, mixed>>
     */
    private function uniqueByParams(array $rows, array $parsed): array
    {
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $parts = [];
            foreach ($parsed as $p) {
                $name = $p['name'];
                $v = $row[$name] ?? '';
                if (is_numeric($v)) {
                    $parts[] = $name.'='.round((float) $v, 6);
                } else {
                    $parts[] = $name.'='.(string) $v;
                }
            }
            $seen[implode('|', $parts)] = $row;
        }

        return array_values($seen);
    }

    /**
     * @param  array<int, array<string, mixed>>  $log
     */
    private function nextBatchNumber(array $log): int
    {
        $max = 0;
        foreach ($log as $row) {
            $max = max($max, (int) ($row['batch'] ?? 0));
        }

        return $max + 1;
    }
}
