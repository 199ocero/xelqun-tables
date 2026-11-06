<?php

namespace Xelqun\Tables\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Creates the two halves of a column: its PHP class and its Vue cell.
 */
class MakeColumnCommand extends Command
{
    private const string CLASS_NAME = '/^[A-Z][A-Za-z0-9]*$/';

    private const string TYPE = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

    protected $signature = 'make:xelqun-column
        {name : The class name, e.g. Sparkline}
        {--type= : The schema type, e.g. acme/sparkline (defaults to app/sparkline)}
        {--force : Overwrite the files if they exist}';

    protected $description = 'Create a Xelqun Tables column: a PHP class and a Vue cell';

    public function handle(): int
    {
        $class = (string) $this->argument('name');
        $type = (string) ($this->option('type') ?: 'app/'.Str::kebab($class));

        if (preg_match(self::CLASS_NAME, $class) !== 1) {
            $this->components->error("[{$class}] is not a valid class name.");

            return self::FAILURE;
        }

        if (preg_match(self::TYPE, $type) !== 1) {
            $this->components->error("[{$type}] needs a vendor prefix, like acme/sparkline. Names without one are reserved for built-in columns.");

            return self::FAILURE;
        }

        $phpPath = app_path("Tables/Columns/{$class}.php");
        $vuePath = resource_path("js/tables/{$class}Cell.vue");

        foreach ([$phpPath, $vuePath] as $path) {
            if (File::exists($path) && ! $this->option('force')) {
                $this->components->error("[{$path}] already exists. Use --force to overwrite it.");

                return self::FAILURE;
            }
        }

        File::ensureDirectoryExists(dirname($phpPath));
        File::ensureDirectoryExists(dirname($vuePath));
        File::put($phpPath, str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ type }}'],
            [$this->laravel->getNamespace().'Tables\\Columns', $class, $type],
            File::get(__DIR__.'/../../stubs/column.php.stub'),
        ));
        File::copy(__DIR__.'/../../stubs/column.vue', $vuePath);

        $this->components->info('Column created.');
        $this->components->bulletList([$phpPath, $vuePath]);
        $this->line("  Register the cell in createXelqunForms():\n");
        $this->line("    components: { '{$type}': () => import('./tables/{$class}Cell.vue') }\n");

        return self::SUCCESS;
    }
}
