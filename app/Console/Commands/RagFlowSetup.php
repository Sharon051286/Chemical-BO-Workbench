<?php

namespace App\Console\Commands;

use App\Services\RagFlowService;
use Illuminate\Console\Command;

/**
 * ragflow:setup
 * --------------
 * 一键初始化「先验数据驱动」所需的 RAGFlow 资源：
 *   1. 确保知识库(dataset) 存在
 *   2. 确保对话助手(chat) 存在并绑定知识库
 *   3. 把 dataset_id / chat_id 写回 .env
 *
 * 前置条件：.env 中已配置 RAGFLOW_API_URL 与 RAGFLOW_API_KEY。
 */
class RagFlowSetup extends Command
{
    protected $signature = 'ragflow:setup';
    protected $description = '创建 RAGFlow 知识库与对话助手，并把 ID 回填到 .env';

    public function handle(): int
    {
        $rf = app(RagFlowService::class);

        if (! $rf->isConfigured()) {
            $this->error('RAGFlow 未配置：请在 .env 设置 RAGFLOW_API_URL 与 RAGFLOW_API_KEY。');
            $this->line('本地部署参考 docker/ragflow/docker-compose.yml，启动后访问 http://localhost:9380 获取 API Key。');

            return self::FAILURE;
        }

        if (! $rf->health()) {
            $this->error('无法连接 RAGFlow（' . config('ragflow.api_url') . '），请确认服务已启动。');

            return self::FAILURE;
        }

        $this->info('正在创建知识库…');
        $datasetId = $rf->ensureDataset();
        $this->info("   dataset_id = {$datasetId}");

        $this->info('正在创建对话助手…');
        $chatId = $rf->ensureChat($datasetId);
        $this->info("   chat_id    = {$chatId}");

        $this->writeEnv($datasetId, $chatId);
        $this->info('已将 dataset_id / chat_id 写入 .env。');
        $this->line('接下来请在「先验数据驱动」菜单中同步历史运行 / 上传文档，再点击「推送语料到 RAGFlow 知识库」。');

        return self::SUCCESS;
    }

    private function writeEnv(string $datasetId, string $chatId): void
    {
        $env = base_path('.env');
        if (! file_exists($env)) {
            return;
        }
        $content = file_get_contents($env);
        $content = preg_replace('/^RAGFLOW_DATASET_ID=.*$/m', 'RAGFLOW_DATASET_ID=' . $datasetId, $content);
        $content = preg_replace('/^RAGFLOW_CHAT_ID=.*$/m', 'RAGFLOW_CHAT_ID=' . $chatId, $content);
        if (! str_contains($content, 'RAGFLOW_DATASET_ID=')) {
            $content .= "\nRAGFLOW_DATASET_ID={$datasetId}\nRAGFLOW_CHAT_ID={$chatId}\n";
        }
        file_put_contents($env, $content);
    }
}
