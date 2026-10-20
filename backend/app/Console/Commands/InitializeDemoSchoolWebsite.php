<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\DemoSchoolWebsiteTemplate;
use Illuminate\Console\Command;

class InitializeDemoSchoolWebsite extends Command
{
    protected $signature = 'school:initialize-demo-website {school : School ID or code}';

    protected $description = 'Initialize demo website content for a school that has no website settings';

    public function handle(DemoSchoolWebsiteTemplate $template): int
    {
        $identifier = (string) $this->argument('school');
        $school = School::query()
            ->where('code', $identifier)
            ->when(ctype_digit($identifier), fn ($query) => $query->orWhereKey((int) $identifier))
            ->first();

        if (! $school) {
            $this->error('School not found.');

            return self::FAILURE;
        }

        if (! $template->initialize($school, true)) {
            $this->warn('This school already has website template content. Existing settings were left unchanged.');

            return self::SUCCESS;
        }

        $this->info("Demo website content initialized for {$school->name} ({$school->code}).");

        return self::SUCCESS;
    }
}
