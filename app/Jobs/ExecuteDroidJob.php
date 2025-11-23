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

class ExecuteDroidJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 1200; // 20 minutes

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
            Log::error('Viniapp not found for ExecuteDroidJob', [
                'viniapp_id' => $this->viniappId,
            ]);
            return;
        }

        try {
            $slug = $viniapp->slug;
            $factoryApiKey = env('FACTORY_API_KEY');
            $slugDirectory = storage_path('deploy/' . $slug);
            
            Log::info('Executing droid command', [
                'viniapp_id' => $viniapp->id,
                'slug' => $slug,
                'directory' => $slugDirectory,
            ]);

            // Ensure the slug directory exists
            if (!is_dir($slugDirectory)) {
                Log::error('Slug directory does not exist', [
                    'viniapp_id' => $viniapp->id,
                    'slug' => $slug,
                    'directory' => $slugDirectory,
                ]);
                $viniapp->update([
                    'status' => 'directory initialization failed',
                ]);
                return;
            }

            $droidCommand = "droid exec -f prompt.md --skip-permissions-unsafe";
            
            Log::info('Running droid exec command', [
                'viniapp_id' => $viniapp->id,
                'slug' => $slug,
                'directory' => $slugDirectory,
                'command' => $droidCommand,
            ]);

            $result = Process::path($slugDirectory)
                ->env(['FACTORY_API_KEY' => $factoryApiKey])
                ->timeout(1200) // 20 minutes timeout
                ->run($droidCommand);

            echo $result->output();
            echo $result->errorOutput();

            if ($result->successful()) {
                // Update the viniapp status to indicate completion
                $viniapp->update([
                    'status' => 'directory initialized',
                ]);

                Log::info('Droid exec completed successfully', [
                    'viniapp_id' => $viniapp->id,
                    'slug' => $slug,
                    'output' => $result->output(),
                ]);
            } else {
                Log::error('Droid exec failed', [
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
            Log::error('Exception in ExecuteDroidJob', [
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

