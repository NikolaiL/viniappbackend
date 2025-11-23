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


class InitializeViniappDirectory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 1800; // 30 minutes (longer than the Process timeout)

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    public Viniapp $viniapp;
    
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
        $this->viniapp = Viniapp::find($this->viniappId);
        try {
            $slug = $this->viniapp->slug;
            $deployPath = storage_path('deploy');

            Log::info('Initializing viniapp directory', [
                'viniapp_id' => $this->viniapp->id,
                'slug' => $slug,
                'deploy_path' => $deployPath,
            ]);

            // Ensure the deploy directory exists
            if (!is_dir($deployPath)) {
                mkdir($deployPath, 0755, true);
            }

            

            // Run the npx command in the deploy directory
            $command = "npx -y create-eth@latest -s hardhat -e NikolaiL/miniapp {$slug}";

            Log::info('Running command to initialize directory', [
                'viniapp_id' => $this->viniapp->id,
                'slug' => $slug,
                'deploy_path' => $deployPath,
                'command' => $command,
            ]);
            
            $result = Process::path($deployPath)
                ->timeout(900) // 10 minutes timeout for the npx command
                ->run($command);

            echo $result->output();
            echo $result->errorOutput();

            if ($result->successful()) {
                Log::info('Npx create-eth command completed successfully', [
                    'viniapp_id' => $this->viniapp->id,
                    'slug' => $slug,
                    'output' => $result->output(),
                ]);
            } else {
                Log::error('Failed to initialize viniapp directory', [
                    'viniapp_id' => $this->viniapp->id,
                    'slug' => $slug,
                    'error' => $result->errorOutput(),
                    'exit_code' => $result->exitCode(),
                ]);

                // Optionally update status to indicate failure
                $this->viniapp->update([
                    'status' => 'directory initialization failed',
                ]);
                return;
            }
            // Copy prompt.md to the directory, replacing **USERPROMPT** with the user's prompt
            $userPrompt = $this->viniapp->prompt ?? '';
            $promptTemplatePath = storage_path('prompt.md');
            $promptDestinationPath = storage_path('deploy/' . $slug . '/prompt.md');
            
            if (file_exists($promptTemplatePath)) {
                $promptContent = file_get_contents($promptTemplatePath);
                $promptContent = str_replace('**USERPROMPT**', $userPrompt, $promptContent);
                file_put_contents($promptDestinationPath, $promptContent);
                
                Log::info('Prompt.md created successfully', [
                    'viniapp_id' => $this->viniapp->id,
                    'slug' => $slug,
                    'destination' => $promptDestinationPath,
                ]);
            } else {
                Log::warning('Prompt template not found, skipping prompt.md creation', [
                    'viniapp_id' => $this->viniapp->id,
                    'template_path' => $promptTemplatePath,
                ]);
            }

            // Run droid exec command with FACTORY_API_KEY environment variable
            $factoryApiKey = 'fk-26rXnp7OZHfQes30NXFN-vo9w7B5Py3qDG9-JJoKhR-JUln_K4l5ERW9hoBXJzx4';
            $slugDirectory = storage_path('deploy/' . $slug);
            
            // Ensure the slug directory exists
            if (!is_dir($slugDirectory)) {
                Log::error('Slug directory does not exist', [
                    'viniapp_id' => $this->viniapp->id,
                    'slug' => $slug,
                    'directory' => $slugDirectory,
                ]);
                $this->viniapp->update([
                    'status' => 'directory initialization failed',
                ]);
                return;
            }

            $droidCommand = "droid exec -f prompt.md --skip-permissions-unsafe";
            
            Log::info('Running droid exec command', [
                'viniapp_id' => $this->viniapp->id,
                'slug' => $slug,
                'directory' => $slugDirectory,
                'command' => $droidCommand,
            ]);

            $result = Process::path($slugDirectory)
                ->env(['FACTORY_API_KEY' => $factoryApiKey])
                ->timeout(1200) // 20 minutes timeout for droid command
                ->run($droidCommand);

            echo $result->output();
            echo $result->errorOutput();

            if ($result->successful()) {
                // Update the viniapp status to indicate completion
                $this->viniapp->update([
                    'status' => 'directory initialized',
                ]);

                Log::info('Droid exec completed successfully', [
                    'viniapp_id' => $this->viniapp->id,
                    'slug' => $slug,
                    'output' => $result->output(),
                ]);
            } else {
                Log::error('Droid exec failed', [
                    'viniapp_id' => $this->viniapp->id,
                    'slug' => $slug,
                    'error' => $result->errorOutput(),
                    'exit_code' => $result->exitCode(),
                ]);

                $this->viniapp->update([
                    'status' => 'directory initialization failed',
                ]);
                return;
            }

        } catch (\Exception $e) {
            Log::error('Exception while initializing viniapp directory', [
                'viniapp_id' => $this->viniapp->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update status to indicate failure
            $this->viniapp->update([
                'status' => 'directory initialization failed',
            ]);

            throw $e;
        }
    }
}

