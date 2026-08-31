<?php

namespace App\Jobs;

use App\Models\EdboRun;
use App\Services\EdboService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunEdboOptimization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $runId;
    public int $tries = 1;
    public int $timeout = 600;
    public int $backoff = 10;

    public function __construct(int $runId)
    {
        $this->runId = $runId;
    }

    public function handle(EdboService $edbo): void
    {
        $run = EdboRun::findOrFail($this->runId);

        $run->update([
            'status' => 'processing',
            'started_at' => now(),
            'error' => null,
        ]);

        try {
            $edbo->runOptimization($run->config, $run->uuid);

            $run->update([
                'status' => 'completed',
                'result_path' => "edbo/runs/{$run->uuid}/results.json",
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = EdboRun::find($this->runId);
        if ($run) {
            $run->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }
}
