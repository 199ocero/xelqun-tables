<?php

namespace Xelqun\Tables\Commands;

use Illuminate\Console\Command;
use Xelqun\Forms\Commands\Concerns\EditsHostApp;
use Xelqun\Tables\Schema\SchemaVersion;

class InstallCommand extends Command
{
    use EditsHostApp;

    private const string NPM_PACKAGE = '@xelqun/tables';

    private const string STYLESHEET = '@xelqun/tables/style.css';

    protected $signature = 'xelqun-tables:install
        {--config : Publish the config file}
        {--without-node : Do not install the npm package}';

    protected $description = 'Install the Xelqun Tables npm package and add its styles to the app';

    public function handle(): int
    {
        if (! $this->option('without-node') && ! $this->installNodePackage(self::NPM_PACKAGE.'@'.SchemaVersion::PACKAGE)) {
            return self::FAILURE;
        }

        $manualStep = $this->importStylesheet(self::STYLESHEET);

        if ($this->option('config')) {
            $this->callSilently('vendor:publish', ['--tag' => 'xelqun-tables-config']);
        }

        if ($manualStep !== null) {
            $this->components->warn('Finish this step by hand:');
            $this->components->bulletList([$manualStep]);

            return self::SUCCESS;
        }

        $this->components->info('Xelqun Tables is installed.');

        return self::SUCCESS;
    }
}
