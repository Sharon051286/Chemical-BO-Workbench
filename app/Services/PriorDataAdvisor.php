<?php

namespace App\Services;

use App\Models\EdboRun;
use RuntimeException;

/**
 * PriorDataAdvisor
 * ----------------
 * 「先验数据驱动」的编排核心：把语料与优化目标送给 RAGFlow（RAG + LLM），
 * 解析其返回的结构化建议；当 RAGFlow 未配置或调用失败时，按配置回退到
 * HeuristicAdvisor（纯历史统计）。对外统一返回一份结构化建议数组。
 */
class PriorDataAdvisor
{
    public function __construct(
        private readonly RagFlowService $ragFlow,
        private readonly HeuristicAdvisor $heuristic,
    ) {}

    /**
     * @param  string  $objective   优化目标描述（如「最大化产率，降低成本」）
     * @param  string  $corpusText  已聚合的语料文本（历史运行 + 上传 + 粘贴）
     * @param  array   $options     额外选项（force_heuristic 等）
     */
    public function advise(string $objective, string $corpusText, array $options = []): array
    {
        $forceHeuristic = (bool) ($options['force_heuristic'] ?? false);

        if (! $forceHeuristic && $this->ragFlow->isConfigured() && $this->ragFlow->health()) {
            try {
                return $this->fromRagFlow($objective, $corpusText);
            } catch (\Throwable $e) {
                if (! config('ragflow.fallback_when_unavailable')) {
                    throw new RuntimeException('RAGFlow 调用失败：' . $e->getMessage());
                }
                // 回退路径
            }
        }

        if (! config('ragflow.fallback_when_unavailable') && $forceHeuristic === false) {
            throw new RuntimeException('RAGFlow 未配置且未启用启发式回退，无法生成建议。');
        }

        return $this->fromHeuristic($objective);
    }

    private function fromRagFlow(string $objective, string $corpusText): array
    {
        $prompt = $this->buildPrompt($objective, $corpusText);
        $raw = $this->ragFlow->chat($prompt);

        $parsed = $this->parseSuggestion($raw);
        $parsed['source'] = 'ragflow';
        $parsed['raw'] = $raw;

        return $parsed;
    }

    private function fromHeuristic(string $objective): array
    {
        $runs = EdboRun::where('status', 'completed')->orderByDesc('finished_at')->get();
        $suggestion = $this->heuristic->advise($runs->all(), $objective);
        // 启发式未吃语料文本，这里把目标附注写入 rationale
        if ($objective !== '' && str_contains($suggestion['rationale'], '（目标：')) {
            // 已含，跳过
        }

        return $suggestion;
    }

    /**
     * 构造给 RAGFlow 的提示词：明确输出 JSON 模式，附上语料。
     */
    private function buildPrompt(string $objective, string $corpusText): string
    {
        $goal = $objective !== '' ? $objective : '推荐能提升目标（如产率）的实验参数与参数空间';

        return <<<PROMPT
你是一名化学实验贝叶斯优化的资深助手。下面是与实验相关的先验语料（历史运行结果、文献片段、实验记录）。

【优化目标】{$goal}

【先验语料】
{$corpusText}

请基于上述语料，给出用于下一次实验设计的建议，并【仅】返回一个 JSON（不要用 ``` 以外的格式，不要写解释性文字）：

{
  "parameters": [ {"name": "参数名", "values": "取值1,取值2,取值3"} ],
  "parameter_space": [ {"name": "参数名", "recommendation": "建议区间/取值", "reason": "依据"} ],
  "recommended_sets": [ {"label": "方案名", "params": {"参数名":"取值"}, "expected_yield": "预计产率", "rationale": "为何推荐"} ],
  "rationale": "总体说明与依据"
}

要求：
1. parameters 直接可用于实验空间定义（name + 逗号分隔的 values）。
2. recommended_sets 给出 2-3 个候选参数组，优先来自语料中表现最好的条件。
3. 若语料不足，请基于化学常识给出合理默认值并在 reason 中说明。
PROMPT;
    }

    /**
     * 从模型文本中解析建议 JSON（兼容 ```json 围栏）。
     */
    private function parseSuggestion(string $raw): array
    {
        $text = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $text, $m)) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            // 退一步：尝试截取第一个 { 到最后一个 }
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('无法解析 RAGFlow 返回的结构化建议。');
        }

        return [
            'parameters' => $decoded['parameters'] ?? [],
            'parameter_space' => $decoded['parameter_space'] ?? [],
            'recommended_sets' => $decoded['recommended_sets'] ?? [],
            'rationale' => $decoded['rationale'] ?? '',
        ];
    }
}
