<?php


namespace Peopleaps\Scorm;


use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Peopleaps\Scorm\Manager\ScormManager;

class ScormServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind('scorm-manager', function ($app) {
            return new ScormManager();
        });
    }

    public function boot()
    {
        $this->offerPublishing();
    }

    protected function offerPublishing()
    {
        // function not available and 'publish' not relevant in Lumen
        if (!function_exists('config_path')) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/scorm.php' => config_path('scorm.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../database/migrations/create_scorm_tables.php.stub' => $this->getMigrationFileName('create_scorm_tables.php'),
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../database/migrations/add_metadata_to_scorm_table.php.stub' => $this->getMigrationFileName('add_metadata_to_scorm_table.php'),
        ], 'migrations');

        $this->publishes([
            __DIR__ . '/../resources/lang/en-US/scorm.php' => resource_path('lang/en-US/scorm.php'),
        ]);
    }

    /**
     * Returns existing migration file if found, else uses the current timestamp.
     *
     * @return string
     */
    protected function getMigrationFileName($migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(Filesystem::class);

        // Extract the base name without extension for glob pattern
        $baseName = pathinfo($migrationFileName, PATHINFO_FILENAME);

        return Collection::make($this->app->databasePath() . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR)
            ->flatMap(function ($path) use ($filesystem, $baseName) {
                return $filesystem->glob($path . '*_' . $baseName . '.php');
            })
            ->push($this->app->databasePath() . "/migrations/{$timestamp}_{$migrationFileName}")
            ->first();
    }
}
