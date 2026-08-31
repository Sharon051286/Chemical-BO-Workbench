<?php

/*
|--------------------------------------------------------------------------
| RAGFlow 配置
|--------------------------------------------------------------------------
| 「先验数据驱动」模块使用 RAGFlow 作为统一的 RAG + LLM 后端：
|   1. 把历史运行 / 上传文档 / 手动粘贴 构建为知识库(dataset)
|   2. 创建对话助手(chat)，绑定该知识库
|   3. 用其 OpenAI 兼容接口做检索增强生成，输出参数与参数空间建议
|
| 本地部署：见 docker/ragflow/docker-compose.yml，启动后访问
| http://localhost:9380 在「头像 → API」页面获取 API Key。
*/

return [
    // RAGFlow 服务地址（docker 默认端口 9380）
    'api_url' => env('RAGFLOW_API_URL', 'http://localhost:9380'),

    // RAGFlow API Key（Web UI「头像 → API」获取）
    'api_key' => env('RAGFLOW_API_KEY', ''),

    // 知识库(dataset) ID；为空时由 php artisan ragflow:setup 创建并回填
    'dataset_id' => env('RAGFLOW_DATASET_ID', ''),

    // 对话助手(chat) ID；为空时由 php artisan ragflow:setup 创建并回填
    'chat_id' => env('RAGFLOW_CHAT_ID', ''),

    // 生成模型。RAGFlow 内置 chat 模型名（如 deepseek-chat / qwen2.5），
    // 也可在 RAGFlow 中绑定外部 LLM。OpenAI 兼容对话使用此字段。
    'model' => env('RAGFLOW_MODEL', 'deepseek-chat'),

    // 请求超时（秒）
    'timeout' => (int) env('RAGFLOW_TIMEOUT', 120),

    // 无 API Key / 服务不可达 / 解析失败时，是否回退到基于历史数据的启发式建议
    'fallback_when_unavailable' => (bool) env('RAGFLOW_FALLBACK', true),

    // 语料与知识库的本地存储根（相对 storage/app）
    'corpus_path' => env('RAGFLOW_CORPUS_PATH', 'private/edbo/corpus'),
];
