<?php

namespace Webbycrown\LaraknowAi\Console\Commands;

use Webbycrown\LaraknowAi\Support\ProjectAnalyzer;
use Illuminate\Console\Command;

class AnalyzeProjectCommand extends Command
{
    protected $signature = 'laraknow:analyze {--fresh : Rebuild the summary without using cache}';

    protected $description = 'Preview the sanitized LaraKnow project analysis summary.';

    public function handle(ProjectAnalyzer $analyzer): int
    {
        $this->line($analyzer->summary((bool) $this->option('fresh')));

        return self::SUCCESS;
    }
}
