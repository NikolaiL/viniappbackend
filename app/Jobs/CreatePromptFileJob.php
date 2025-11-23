<?php

namespace App\Jobs;

use App\Models\Viniapp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Jobs\ExecuteDroidJob;

class CreatePromptFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 60; // 1 minute

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
            Log::error('Viniapp not found for CreatePromptFileJob', [
                'viniapp_id' => $this->viniappId,
            ]);
            return;
        }

        try {
            $slug = $viniapp->slug;
            $userPrompt = $viniapp->prompt ?? '';
            $promptTemplatePath = storage_path('prompt.md');
            $promptDestinationPath = storage_path('deploy/' . $slug . '/prompt.md');

            $endpointsFile = storage_path('all_x402scan_endpoints.json'); 
            $endpointsDestinationPath = storage_path('deploy/' . $slug . '/all_x402scan_endpoints.json');

            if (file_exists($endpointsFile)) {
                file_put_contents($endpointsDestinationPath, file_get_contents($endpointsFile));
            }
            
            Log::info('Creating prompt.md file', [
                'viniapp_id' => $viniapp->id,
                'slug' => $slug,
                'template_path' => $promptTemplatePath,
                'destination_path' => $promptDestinationPath,
            ]);

            if (file_exists($promptTemplatePath)) {
                $promptContent = file_get_contents($promptTemplatePath);
                $promptContent = str_replace('**USERPROMPT**', $userPrompt, $promptContent);
                
                // Ensure the directory exists
                $destinationDir = dirname($promptDestinationPath);
                if (!is_dir($destinationDir)) {
                    mkdir($destinationDir, 0755, true);
                }
                
                file_put_contents($promptDestinationPath, $promptContent);
                
                Log::info('Prompt.md created successfully', [
                    'viniapp_id' => $viniapp->id,
                    'slug' => $slug,
                    'destination' => $promptDestinationPath,
                ]);

                // update status
                $viniapp->update([
                    'status' => 'prompt file created successfully',
                ]);

                // Dispatch the next job in the sequence
                ExecuteDroidJob::dispatch($this->viniappId);
            } else {
                Log::warning('Prompt template not found, skipping prompt.md creation', [
                    'viniapp_id' => $viniapp->id,
                    'template_path' => $promptTemplatePath,
                ]);

                // Still dispatch the next job even if prompt.md doesn't exist
                ExecuteDroidJob::dispatch($this->viniappId);
            }
        } catch (\Exception $e) {
            Log::error('Exception in CreatePromptFileJob', [
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

