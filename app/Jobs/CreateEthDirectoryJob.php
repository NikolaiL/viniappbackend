<?php

namespace App\Jobs;

use App\Models\Viniapp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use App\Jobs\CreatePromptFileJob;

class CreateEthDirectoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $viniappId
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $viniapp = Viniapp::find($this->viniappId);
        
        if (!$viniapp) {
            Log::error('Viniapp not found for CreateEthDirectoryJob', [
                'viniapp_id' => $this->viniappId,
            ]);
            return;
        }

        try {
            $slug = $viniapp->slug;
            $deployPath = storage_path('deploy');

            Log::info('Creating eth directory', [
                'viniapp_id' => $viniapp->id,
                'slug' => $slug,
                'deploy_path' => $deployPath,
            ]);

            // Ensure the deploy directory exists
            if (!is_dir($deployPath)) {
                mkdir($deployPath, 0755, true);
            }

            // Run the npx command in the deploy directory
            $command = "npx -y create-eth@latest -s hardhat -e NikolaiL/miniapp {$slug}";

            Log::info('Running npx create-eth command', [
                'viniapp_id' => $viniapp->id,
                'slug' => $slug,
                'command' => $command,
            ]);
            
            $result = Process::path($deployPath)
                ->timeout(900) // 15 minutes timeout
                ->run($command);

            echo $result->output();
            echo $result->errorOutput();

            if ($result->successful()) {
                Log::info('Npx create-eth command completed successfully', [
                    'viniapp_id' => $viniapp->id,
                    'slug' => $slug,
                    'output' => $result->output(),
                ]);

                // update status
                $viniapp->update([
                    'status' => 'repository directory initialized',
                ]);

                // Dispatch the next job in the sequence
                CreatePromptFileJob::dispatch($this->viniappId);
            } else {
                Log::error('Failed to create eth directory', [
                    'viniapp_id' => $viniapp->id,
                    'slug' => $slug,
                    'error' => $result->errorOutput(),
                    'exit_code' => $result->exitCode(),
                ]);

                $viniapp->update([
                    'status' => 'directory initialization failed',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception in CreateEthDirectoryJob', [
                'viniapp_id' => $this->viniappId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $viniapp = Viniapp::find($this->viniappId);
            if ($viniapp) {
                $viniapp->update([
                    'status' => 'directory initialization failed',
                ]);
            }

            throw $e;
        }
    }
}

