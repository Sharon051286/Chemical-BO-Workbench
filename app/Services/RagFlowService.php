<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * RagFlowService
 * --------------
 * 对 RAGFlow 的轻量 HTTP 封装。RAGFlow 同时提供「RAG 检索」与
 * 「OpenAI 兼容对话」两类能力，本服务把二者打通，使「先验数据驱动」
 * 模块只需与一个后端交互：
 *
 *   - 知识库(dataset)：把历史运行 / 上传文档 / 粘贴文本 建库
 *   - 对话助手(chat)：绑定知识库，做检索增强生成
 *   - 对话：POST /api/v1/chats_openai/{chat_id}/chat/completions（OpenAI 兼容）
 *
 * 所有公开方法都对「服务不可达 / 未配置」做了容错，调用方结合
 * isConfigured() 与 fallback 策略决定是否回退到启发式建议。
 */
class RagFlowService
{
    /** RAGFlow 就绪（URL + Key 均存在） */
    public function isConfigured(): bool
    {
        $url = config('ragflow.api_url');
        $key = config('ragflow.api_key');

        return filter_var($url, FILTER_VALIDATE_URL) !== false && trim((string) $key) !== '';
    }

    /** 服务是否在线 */
    public function health(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $res = Http::timeout(10)
                ->withToken(config('ragflow.api_key'), 'Bearer')
                ->get(rtrim(config('ragflow.api_url'), '/') . '/api/v1/health');

            return $res->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * 确保知识库存在：若 config 中已有 dataset_id 则直接返回；
     * 否则创建名为「EDBO 先验语料」的知识库。
     */
    public function ensureDataset(): string
    {
        $datasetId = config('ragflow.dataset_id');
        if ($datasetId !== '') {
            return $datasetId;
        }

        $res = Http::timeout(config('ragflow.timeout'))
            ->withToken(config('ragflow.api_key'), 'Bearer')
            ->post(rtrim(config('ragflow.api_url'), '/') . '/api/v1/datasets', [
                'name' => 'EDBO 先验语料',
                'permission' => 'me',
            ]);

        $data = $res->json();
        if (($data['code'] ?? -1) !== 0 || empty($data['data']['id'])) {
            throw new RuntimeException('创建 RAGFlow 知识库失败：' . ($data['message'] ?? $res->body()));
        }

        return $data['data']['id'];
    }

    /**
     * 上传一段文本作为文档到知识库。
     * RAGFlow 上传接口要求 multipart file，这里把文本落临时文件后 attach。
     */
    public function uploadText(string $name, string $content, ?string $datasetId = null): bool
    {
        $datasetId = $datasetId ?? $this->ensureDataset();
        $ext = str_ends_with($name, '.md') ? 'md' : 'txt';
        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]+/', '_', $name) . '.' . $ext;

        $tmp = tempnam(sys_get_temp_dir(), 'rag_');
        if ($tmp === false) {
            throw new RuntimeException('无法创建临时文件用于 RAGFlow 上传');
        }
        file_put_contents($tmp, $content);

        try {
            $res = Http::timeout(config('ragflow.timeout'))
                ->withToken(config('ragflow.api_key'), 'Bearer')
                ->attach('file', file_get_contents($tmp), $safeName)
                ->post(rtrim(config('ragflow.api_url'), '/') . '/api/v1/datasets/' . $datasetId . '/documents');

            $data = $res->json();

            return ($data['code'] ?? -1) === 0;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * 上传本地文件（用户上传的文档）到知识库。
     */
    public function uploadFile(string $absPath, string $originalName, ?string $datasetId = null): bool
    {
        $datasetId = $datasetId ?? $this->ensureDataset();

        $res = Http::timeout(config('ragflow.timeout'))
            ->withToken(config('ragflow.api_key'), 'Bearer')
            ->attach('file', file_get_contents($absPath), $originalName)
            ->post(rtrim(config('ragflow.api_url'), '/') . '/api/v1/datasets/' . $datasetId . '/documents');

        $data = $res->json();

        return ($data['code'] ?? -1) === 0;
    }

    /**
     * 确保对话助手存在：若已有 chat_id 返回；否则创建并绑定知识库。
     */
    public function ensureChat(?string $datasetId = null): string
    {
        $chatId = config('ragflow.chat_id');
        if ($chatId !== '') {
            return $chatId;
        }

        $datasetId = $datasetId ?? $this->ensureDataset();
        $res = Http::timeout(config('ragflow.timeout'))
            ->withToken(config('ragflow.api_key'), 'Bearer')
            ->post(rtrim(config('ragflow.api_url'), '/') . '/api/v1/chats', [
                'name'        => 'EDBO 先验建议助手',
                'dataset_ids' => [$datasetId],
                'prompt'      => '你是化学实验贝叶斯优化的资深助手。基于知识库中的历史实验数据与文献，'
                    . '为用户推荐实验参数、参数空间与候选参数组。务必只输出结构化 JSON（含 parameters、'
                    . 'parameter_space、recommended_sets、rationale 字段），不要额外解释。',
            ]);

        $data = $res->json();
        if (($data['code'] ?? -1) !== 0 || empty($data['data']['id'])) {
            throw new RuntimeException('创建 RAGFlow 对话助手失败：' . ($data['message'] ?? $res->body()));
        }

        return $data['data']['id'];
    }

    /**
     * 对话（OpenAI 兼容）。返回模型生成的文本（可能含 ```json 围栏）。
     */
    public function chat(string $message, ?string $chatId = null): string
    {
        $chatId = $chatId ?? $this->ensureChat();
        $url = rtrim(config('ragflow.api_url'), '/') . '/api/v1/chats_openai/' . $chatId . '/chat/completions';

        $res = Http::timeout(config('ragflow.timeout'))
            ->withToken(config('ragflow.api_key'), 'Bearer')
            ->post($url, [
                'model'    => config('ragflow.model'),
                'messages' => [
                    ['role' => 'user', 'content' => $message],
                ],
                'stream' => false,
            ]);

        $data = $res->json();
        if (($data['code'] ?? 0) !== 0) {
            throw new RuntimeException('RAGFlow 对话失败：' . ($data['message'] ?? $res->body()));
        }

        // OpenAI 兼容结构
        if (! empty($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }

        // RAGFlow 原生结构兜底
        if (! empty($data['data']['answer'])) {
            return $data['data']['answer'];
        }

        throw new RuntimeException('RAGFlow 对话返回结构异常：' . $res->body());
    }

    /**
     * 列出知识库中的文档（GET）。返回 docs 数组，每个文档含
     * id / name / size / run（处理状态）/ chunk_count / create_date 等。
     * 注意：文档处理状态字段是 run（UNSTART|RUNNING|DONE|FAIL|CANCEL），不是 status。
     */
    public function listDocuments(string $datasetId, int $page = 1, int $pageSize = 200): array
    {
        $res = Http::timeout(config('ragflow.timeout'))
            ->withToken(config('ragflow.api_key'), 'Bearer')
            ->get(rtrim(config('ragflow.api_url'), '/') . '/api/v1/datasets/' . $datasetId . '/documents', [
                'page' => $page,
                'page_size' => $pageSize,
                'orderby' => 'create_time',
                'desc' => true,
            ]);

        $data = $res->json();
        if (($data['code'] ?? -1) !== 0) {
            throw new RuntimeException('获取 RAGFlow 文档列表失败：' . ($data['message'] ?? $res->body()));
        }

        return $data['data']['docs'] ?? [];
    }

    /**
     * 删除知识库中的文档（DELETE）。RAGFlow 以 body {"ids":[...], "delete_all":false} 接收。
     */
    public function deleteDocuments(string $datasetId, array $docIds): bool
    {
        $res = Http::timeout(config('ragflow.timeout'))
            ->withToken(config('ragflow.api_key'), 'Bearer')
            ->withHeaders(['Content-Type' => 'application/json'])
            ->delete(rtrim(config('ragflow.api_url'), '/') . '/api/v1/datasets/' . $datasetId . '/documents', [
                'ids' => $docIds,
                'delete_all' => false,
            ]);

        $data = $res->json();

        return ($data['code'] ?? -1) === 0;
    }
}
