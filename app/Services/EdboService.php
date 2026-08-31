<?php

namespace App\Services;

use App\Jobs\RunEdboOptimization;
use App\Models\EdboRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * EdboService
 * -----------
 * 资深开发视角下的「外部进程桥接」实现。
 *
 * 职责边界清晰：本服务只负责把 PHP 侧的配置可靠地交给 Python 端的 EDBO
 * 运行器，并安全地把结果拿回来。它不关心优化算法本身，也不处理 UI。
 *
 * 设计要点：
 *  - 使用 Laravel 官方 Process Facade，自带超时、环境变量、错误处理
 *  - 配置与结果都走文件系统（JSON），隔离两个运行时（PHP/Python），避免管道断裂
 *  - 所有路径使用绝对 Windows 路径，规避工作目录不确定性
 *  - 进程失败时抛出带上下文的异常，便于上层 Livewire 组件友好提示
 */
class EdboService
{
    /** micromamba 可执行文件路径 */
    private const MICROMAMBA_BIN = 'D:\\miniconda_install\\Library\\bin\\micromamba.exe';

    /** conda 根目录（环境 edbo 所在） */
    private const MAMBA_ROOT_PREFIX = 'D:\\miniconda3';

    /** EDBO 虚拟环境的绝对前缀路径（micromamba -p 直接定位，避免 MAMBA_ROOT_PREFIX 不生效） */
    private const EDBO_ENV_PREFIX = 'D:\\miniconda3\\envs\\edbo';

    /** Ax 虚拟环境的绝对前缀路径（Python 3.11，独立于 EDBO 的 3.7） */
    private const AX_ENV_PREFIX = 'D:\\miniconda3\\envs\\edbo-ax';

    /** EDBO 虚拟环境的 python.exe */
    private const EDBO_PYTHON = 'D:\\miniconda3\\envs\\edbo\\python.exe';

    /** Ax 虚拟环境的 python.exe */
    private const AX_PYTHON = 'D:\\miniconda3\\envs\\edbo-ax\\python.exe';

    /** EDBO Python 运行器脚本（相对于项目根） */
    private const RUNNER_SCRIPT = 'scripts\\edbo_runner.py';

    /** Ax Python 运行器脚本 */
    private const AX_RUNNER_SCRIPT = 'scripts\\ax_runner.py';

    /** EDBO 运行环境健康检查脚本 */
    private const EDBO_HEALTHCHECK_SCRIPT = 'scripts\\edbo_healthcheck.py';

    /** Ax 运行环境健康检查脚本 */
    private const AX_HEALTHCHECK_SCRIPT = 'scripts\\ax_healthcheck.py';
    private const MNL_RUNNER_SCRIPT = 'scripts\\mnl_runner.py';
    private const MNL_HEALTHCHECK_SCRIPT = 'scripts\\mnl_healthcheck.py';

    /** EDBO 批处理包装器：确保 Web 上下文下 micromamba 获得合法控制台环境 */
    private const BAT_WRAPPER = 'scripts\\edbo_run.bat';

    /** Ax 批处理包装器 */
    private const AX_BAT_WRAPPER = 'scripts\\ax_run.bat';

    /** 进程超时（秒）：EDBO 训练高斯过程可能耗时，给足余量 */
    private const TIMEOUT_SECONDS = 600;

    /**
     * 执行一次 EDBO 优化。
     *
     * @param  array  $config  来自前端的结构化配置
     * @return array           解析后的结果数组（status / best / convergence / experiments ...）
     *
     * @throws RuntimeException 当进程失败或结果无法解析时
     */
    public function dispatchOptimization(array $config, ?string $taskUuid = null): EdboRun
    {
        $this->assertEnvironment();

        $uuid = (string) Str::uuid();
        // 未显式传入 task_uuid 时，本次运行自身即开启一个新优化课题（任务）。
        // 显式传入则表示本次推荐属于该课题的下一个批次，与历史批次共享同一任务。
        $effectiveTaskUuid = $taskUuid ?? (string) Str::uuid();
        $runDir = 'edbo/runs/' . $uuid;
        $configPath = $runDir . '/config.json';
        $outputPath = $runDir . '/results.json';

        $disk = Storage::disk('local');
        $disk->makeDirectory($runDir);
        $disk->put($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $disk->put($outputPath, '');

        $run = EdboRun::create([
            'uuid' => $uuid,
            'task_uuid' => $effectiveTaskUuid,
            'engine' => $config['engine'] ?? 'ax',
            'status' => 'pending',
            'config' => $config,
            'result_path' => $outputPath,
        ]);

        RunEdboOptimization::dispatch($run->id);

        return $run;
    }

    public function runOptimization(array $config, ?string $runUuid = null): array
    {
        // EDBO/Ax 训练高斯过程可能耗时 30-60 秒，php -S 内置服务器默认 30 秒超时
        // 会中断 Livewire 请求。这里取消 PHP 执行时间限制，由 runNative 的
        // $timeout 参数（600 秒）控制实际进程超时。
        set_time_limit(0);

        $this->assertEnvironment();

        $disk = Storage::disk('local');

        // 1) 写出配置 JSON（每次独立目录，避免并发冲突）
        if ($runUuid !== null) {
            $runDir = 'edbo/runs/' . $runUuid;
        } else {
            $runDir = 'edbo/runs/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        }
        $configPath = $runDir . '/config.json';
        $outputPath = $runDir . '/results.json';

        $disk->makeDirectory($runDir);
        $disk->put($configPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $disk->put($outputPath, '');

        $absConfig = $this->absoluteStoragePath($configPath);
        $absOutput = $this->absoluteStoragePath($outputPath);

        $engine = $config['engine'] ?? 'ax';
        $command = $this->getEngineRunnerCommand($engine, $absConfig, $absOutput);
        $output = $this->runNative($command, self::TIMEOUT_SECONDS);

        $payload = null;
        if ($disk->exists($outputPath) && $disk->size($outputPath) > 0) {
            $payload = json_decode($disk->get($outputPath), true);
        }

        if (is_array($payload) && ($payload['status'] ?? null) === 'error') {
            throw new RuntimeException(ucfirst($engine) . ' 运行器返回错误：' . ($payload['message'] ?? '未知错误'));
        }

        if ($output === null) {
            throw new RuntimeException(ucfirst($engine) . ' 进程执行失败：进程启动超时或退出码非零，请检查日志。');
        }

        if (! is_array($payload)) {
            throw new RuntimeException(ucfirst($engine) . ' 未产出结果文件，请检查 Python 运行器日志。');
        }

        if (! is_array($payload) || ! isset($payload['status'])) {
            throw new RuntimeException(ucfirst($engine) . ' 结果格式异常，无法解析。');
        }

        return array_merge($payload, ['engine' => $engine]);
    }

    public function getRunResult(EdboRun $run): array
    {
        $disk = Storage::disk('local');
        if (! $run->result_path || ! $disk->exists($run->result_path)) {
            throw new RuntimeException('结果文件不存在：' . ($run->result_path ?? '未知路径'));
        }

        $payload = json_decode($disk->get($run->result_path), true);
        if (! is_array($payload) || ! isset($payload['status'])) {
            throw new RuntimeException('结果文件格式异常，无法解析。');
        }

        if (($payload['status'] ?? null) === 'error') {
            throw new RuntimeException('Python 运行器返回错误：' . ($payload['message'] ?? '未知错误'));
        }

        return array_merge($payload, ['engine' => $run->engine]);
    }

    /**
     * 返回当前桥接环境是否就绪（供 UI 自检展示）。
     * 检查 Ax 与 MNL 两个引擎（均运行于 edbo-ax 环境）。
     */
    public function healthCheck(): array
    {
        $micromambaOk = file_exists(self::MICROMAMBA_BIN);
        $pythonOk = false;
        $axOk = false;
        $mnlOk = false;

        // 直接调用 edbo-ax 的 python.exe，并把 conda Library\bin 放进 PATH。
        // 不要走 micromamba run：它会再生成一份 bat，遇上中文项目路径时 cmd 解析失败。
        if (file_exists(self::AX_PYTHON)) {
            $axOutput = $this->runNative(
                [self::AX_PYTHON, base_path(self::AX_HEALTHCHECK_SCRIPT)],
                120
            );
            $axOk = str_contains($axOutput ?? '', 'ax-ok');
        }

        // MNL-BO 健康检查：纯 numpy 实现，复用 edbo-ax 环境的 python（numpy 1.26 可用）。
        // 不再走 legacy edbo 环境（Py3.7 numpy DLL 损坏）。
        if (file_exists(self::AX_PYTHON)) {
            $mnlOutput = $this->runNative([self::AX_PYTHON, base_path(self::MNL_HEALTHCHECK_SCRIPT)], 60);
            $mnlOk = str_contains($mnlOutput ?? '', 'mnl-ok');
            $pythonOk = $mnlOutput !== null;
        }

        return [
            'micromamba' => $micromambaOk,
            'python_env' => $pythonOk,
            'ax_importable' => $axOk,
            'mnl_importable' => $mnlOk,
            'ready' => $axOk,
        ];
    }

    /**
     * 原生 proc_open 执行命令，只传最小环境变量。
     *
     * 解决 Windows 下 $_ENV 超大（>32767 UTF-16）导致 Process Facade 失败的问题。
     * 使用文件重定向替代管道捕获输出，规避 Windows 管道 EOF/阻塞问题。
     * 返回 stdout 内容，失败返回 null。
     *
     * @param  array<int, string>|string  $command
     */
    private function runNative(array|string $command, int $timeout): ?string
    {
        // 最小环境变量：只保留必要的 conda 与 Windows 路径，避免 Windows 环境块超过上限。
        $env = [
            'PATH' => implode(';', [
                'D:\\miniconda3\\envs\\edbo\\Library\\bin',
                'D:\\miniconda3\\envs\\edbo\\Scripts',
                'D:\\miniconda3\\envs\\edbo-ax\\Library\\bin',
                'D:\\miniconda3\\envs\\edbo-ax\\Scripts',
                'D:\\miniconda3\\Library\\bin',
                'D:\\miniconda_install\\Library\\bin',
                'C:\\Windows\\System32',
                'C:\\Windows',
            ]),
            'SystemRoot' => $_SERVER['SystemRoot'] ?? 'C:\\Windows',
            'WINDIR' => $_SERVER['WINDIR'] ?? 'C:\\Windows',
            'TEMP' => sys_get_temp_dir(),
            'TMP' => sys_get_temp_dir(),
            'PATHEXT' => $_SERVER['PATHEXT'] ?? '.COM;.EXE;.BAT;.CMD',
            'ComSpec' => $_SERVER['ComSpec'] ?? 'C:\\Windows\\System32\\cmd.exe',
            'MAMBA_ROOT_PREFIX' => self::MAMBA_ROOT_PREFIX,
            'USERPROFILE' => getenv('USERPROFILE'),
            'HOMEDRIVE' => getenv('HOMEDRIVE'),
            'HOMEPATH' => getenv('HOMEPATH'),
            'APPDATA' => getenv('APPDATA'),
            'LOCALAPPDATA' => getenv('LOCALAPPDATA'),
            'PYTHONUTF8' => '1',
            'PYTHONIOENCODING' => 'utf-8',
        ];

        $stdoutFile = tempnam(sys_get_temp_dir(), 'edbo_out_');
        $stderrFile = tempnam(sys_get_temp_dir(), 'edbo_err_');
        $batFile = null;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        if (is_array($command)) {
            // 数组形式走 CreateProcess，不经过 cmd.exe，中文路径不会被 bat 编码毁掉。
            $proc = @proc_open($command, $descriptors, $pipes, null, $env);
        } else {
            $batFile = tempnam(sys_get_temp_dir(), 'edbo_cmd_').'.bat';
            $batBody = "@echo off\r\n".$command.' > "'.$stdoutFile.'" 2> "'.$stderrFile."\"\r\n";
            file_put_contents($batFile, $batBody);
            $proc = @proc_open('cmd.exe /c "'.$batFile.'"', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $env);
        }

        if (! is_resource($proc)) {
            @unlink($stdoutFile);
            @unlink($stderrFile);
            if ($batFile) {
                @unlink($batFile);
            }
            return null;
        }

        fclose($pipes[0]);
        if (isset($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2])) {
            fclose($pipes[2]);
        }

        // 等待进程退出
        $start = time();
        while (true) {
            $status = proc_get_status($proc);
            if (! $status['running']) {
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc, 9);
                break;
            }
            usleep(100000); // 100ms
        }

        $exitCode = proc_close($proc);

        // 从文件读取输出
        $output = @file_get_contents($stdoutFile) ?: '';
        $errOutput = @file_get_contents($stderrFile) ?: '';

        @unlink($stdoutFile);
        @unlink($stderrFile);
        if ($batFile) {
            @unlink($batFile);
        }

        $cmdPrefix = is_array($command) ? implode(' ', array_slice($command, 0, 4)) : mb_substr($command, 0, 120);

        // 调试日志
        @file_put_contents(
            storage_path('logs/edbo_bridge.log'),
            date('Y-m-d H:i:s') . ' | cmd=' . substr($cmdPrefix, 0, 80)
            . ' | exit=' . $exitCode
            . ' | out=' . substr(trim($output), 0, 200)
            . ' | err=' . substr(trim($errOutput), 0, 200)
            . PHP_EOL,
            FILE_APPEND
        );

        if ($exitCode !== 0) {
            return null;
        }

        return $output;
    }

    private function assertEnvironment(): void
    {
        if (! file_exists(self::MICROMAMBA_BIN) && ! file_exists(self::EDBO_PYTHON)) {
            throw new RuntimeException('未找到可用 Python 运行环境，请确认 EDBO 已正确部署。');
        }
    }

    private function getEnginePythonExecutable(string $engine): ?string
    {
        // Ax 与 MNL 均运行于 edbo-ax 环境（Py3.11，numpy/ax/botorch/rdkit 齐全）。
        // legacy EDBO 引擎（edbo Py3.7 环境）已弃用：其 numpy DLL 损坏，无法运行。
        if (in_array($engine, ['ax', 'mnl'], true)) {
            return file_exists(self::AX_PYTHON) ? self::AX_PYTHON : null;
        }

        return null;
    }

    private function getEngineRunnerCommand(string $engine, string $configPath, string $outputPath): array
    {
        $script = base_path(
            $engine === 'ax'
                ? self::AX_RUNNER_SCRIPT
                : ($engine === 'mnl' ? self::MNL_RUNNER_SCRIPT : self::RUNNER_SCRIPT)
        );

        $python = $this->getEnginePythonExecutable($engine);
        if ($python !== null) {
            return [$python, $script, '--config', $configPath, '--output', $outputPath];
        }

        $bat = base_path($engine === 'ax' ? self::AX_BAT_WRAPPER : self::BAT_WRAPPER);

        return [$bat, 'optimize', '--config', $configPath, '--output', $outputPath];
    }

    /**
     * 把 storage 相对路径转为绝对路径（兼容 Windows 反斜杠）。
     *
     * 关键：必须使用 Storage 磁盘自身的根目录（Laravel 12 起 local 磁盘根目录为
     * storage/app/private，而非 storage/app），否则 PHP 写入位置与传给 Python 的
     * 路径不一致，导致运行器找不到配置文件。这里直接委托磁盘解析，避免硬编码根目录。
     */
    private function absoluteStoragePath(string $relative): string
    {
        return Storage::disk('local')->path($relative);
    }
}
