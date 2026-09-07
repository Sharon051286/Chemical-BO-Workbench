# EDBO 实验优化工作台

面向化学实验的贝叶斯优化界面：定义参数空间，调用 Ax / MNL 推荐下一批条件，回填实测后再迭代。

日常请只用这一套，不要打开旧的 Livewire 页面。

## 正确页面

- 工作台：**http://127.0.0.1:8080/**
- API（不要当日常界面）：http://127.0.0.1:8000/

双击 `打开正确工作台.bat`，或运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\open-workbench.ps1
```

对应 GitHub 仓库是本项目自己的 `main`，不是旧仓库 `Sharon051286/Chemical-BO` 上那份 8 月 Livewire `main`。

## 本地启动

需要：PHP 8.3+（本机常用 `D:\php\php.exe`）、Node.js、以及 `edbo-ax` Python 环境。

```powershell
# 后端
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate

# 前端
cd frontend
npm install
npm run dev
```

前端会把 `/api` 代理到 `127.0.0.1:8000`。

## 目录

- `frontend/` — TanStack 工作台（课题、分析、演示/正式）
- `app/` `routes/api.php` — Laravel API 与优化调度
- `scripts/` — Ax / MNL 运行器与一键打开脚本
