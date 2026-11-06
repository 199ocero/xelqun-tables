<?php

namespace Xelqun\Tables\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeTableCommand extends Command
{
    private const string CLASS_NAME = '/^[A-Z][A-Za-z0-9]*$/';

    protected $signature = 'make:xelqun-table
        {name : The class name, e.g. ProductsTable}
        {--model= : The model it lists (defaults to the name without "Table", in the singular)}
        {--force : Overwrite the file if it exists}';

    protected $description = 'Create a Xelqun Tables table class';

    public function handle(): int
    {
        $class = (string) $this->argument('name');
        $model = (string) ($this->option('model') ?: Str::singular(Str::beforeLast($class, 'Table')));

        foreach ([$class, $model] as $name) {
            if (preg_match(self::CLASS_NAME, $name) !== 1) {
                $this->components->error("[{$name}] is not a valid class name.");

                return self::FAILURE;
            }
        }

        $path = app_path("Tables/{$class}.php");

        if (File::exists($path) && ! $this->option('force')) {
            $this->components->error("[{$path}] already exists. Use --force to overwrite it.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ modelNamespace }}', '{{ model }}'],
            [$this->laravel->getNamespace().'Tables', $class, $this->laravel->getNamespace().'Models', $model],
            File::get(__DIR__.'/../../stubs/table.php.stub'),
        ));

        $this->components->info('Table created.');
        $this->components->bulletList([$path]);

        return self::SUCCESS;
    }
}
