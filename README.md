# Chemical-BO Workbench

本地优先的**化学实验贝叶斯优化工作台**：定义反应条件空间，由优化引擎推荐下一批实验，回填实测结果后再迭代。

面向做反应优化、条件筛选的科研人员与工艺开发者。界面是中文左右分栏工作台，不需要写代码就能跑一轮推荐。

> English: A local Bayesian optimization workbench for chemical experiments. Define the search space, get the next batch of conditions, enter measured yields, and continue. The daily UI is Chinese.

## 它能做什么

1. **定义实验空间** — 连续数值（温度、时间、当量）、类别试剂（碱、配体）、以及用 SMILES 表示的溶剂/底物（分子描述符编码）。
2. **选择引擎** — **Ax**（Meta Ax + BoTorch 高斯过程，支持多目标）或 **MNL-BO**（多项 Logit 代理，更适合类别/混合变量）。
3. **拿到下一批推荐** — 指定批次大小与采集策略（EI / TS / PI / UCB 等），运行后得到推荐条件与预测目标。
4. **回填实测、继续优化** — 在分析页或工作台录入产率（及可选第二目标），同一课题接着推荐。
5. **课题管理** — 查看待录入 / 运行中 / 已完成 / 失败，继续优化或删除课题。
6. **演示模式** — 右上角可切换演示数据，先熟悉界面，不必立刻配置 Python 引擎。

日常请打开：

**http://127.0.0.1:8080/**

`http://127.0.0.1:8000/` 只是后端 API，不要当工作台使用。

## 典型流程

```
新建课题（命名） → 配置参数与目标 → 运行推荐
        ↑                              ↓
   继续优化 ← 录入实测 ← 做实验
```

- 第一次正式运行必须填写课题名称，后续同一课题会沿用，不会每次生成无名课题。
- 推荐表里的「预测」不是实验结果；做完实验后把实测填回去，模型才会更新。
- 多目标时（例如产率 + 成本/时间/杂质），Ax 会走 Pareto / 超体积流程。

## 运行环境

当前主要在 **Windows** 上验证。需要同时准备：

| 组件 | 建议版本 | 用途 |
| --- | --- | --- |
| PHP | 8.3+ | Laravel API（默认 `127.0.0.1:8000`） |
| Composer | 2.x | PHP 依赖 |
| Node.js + npm | 20+ | 前端工作台（默认 `127.0.0.1:8080`） |
| Python | 3.11 | 优化引擎 |
| Conda / Miniconda | 可选但推荐 | 隔离 Ax / RDKit 环境 |

Ax 引擎需要能 `import`：`numpy`、`pandas`、`ax`、`botorch`，以及做分子编码时的 `rdkit`。MNL-BO 主要依赖 `numpy`。

> 目前 Python 解释器路径写在 `app/Services/EdboService.php`（默认查找 `edbo-ax` 环境）。换机器时请改成你本机的 `python.exe`，否则界面能开、推荐会失败。

## 安装

```bash
git clone https://github.com/Sharon051286/Chemical-BO-Workbench.git
cd Chemical-BO-Workbench
```

### 后端

```bash
composer install
copy .env.example .env          # macOS / Linux: cp .env.example .env
php artisan key:generate
php artisan migrate
```

默认使用 SQLite，一般不用单独装数据库。

### 前端

```bash
cd frontend
npm install
```

前端会把 `/api` 代理到 `127.0.0.1:8000`。

### 一键启动（Windows）

双击仓库里的 `打开正确工作台.bat`，或：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\open-workbench.ps1
```

脚本会启动 API（8000）和 Vite 工作台（8080），并打开浏览器。

也可以分两个终端手动启动：

```bash
# 终端 1 — 仓库根目录
php artisan serve --host=127.0.0.1 --port=8000

# 终端 2
cd frontend
npm run dev
```

然后访问 http://127.0.0.1:8080/ 。

## 仓库结构

```
frontend/          工作台界面（课题、分析、演示/正式）
app/               Laravel API 与优化调度
routes/api.php     HTTP 接口
scripts/           Ax / MNL 运行器与 Windows 启动脚本
storage/           本地运行配置与结果（不要提交实验数据）
```

## 使用注意

- 这是**本机工具**，不是在线算力平台；实验数据默认只存在你这台电脑。
- 推荐条件仍需结合化学常识与安全规范复核，模型不会检查不相容试剂或危险组合。
- 采集函数下拉项在部分 Ax 配置下可能被引擎内部策略覆盖。
- 运行中的进度条是耗时估计，不是引擎内部步数。
- 旧版 Livewire 页面仍可能挂在 8000 端口，请以 8080 工作台为准。

## 致谢

优化内核分别基于 [Ax](https://ax.dev/) / [BoTorch](https://botorch.org/)，以及 MNL-BO（多项 Logit 代理、混合变量贝叶斯优化）。分子描述符编码使用 RDKit。

## 许可

应用骨架基于 Laravel（MIT）。若你基于本仓库发表结果，请同时注明所用优化引擎（Ax 或 MNL-BO）及其文献。
