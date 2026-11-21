<?php

namespace App\Console\Commands;

use App\Models\Spa;
use Illuminate\Console\Command;

class SetSpaStatusCommand extends Command
{
    protected $signature = 'yospa:set-spa-status
                            {spa : The ID or slug of the spa}
                            {status : The status to set (active or suspended)}';

    protected $description = 'Activate or suspend a spa';

    public function handle(): int
    {
        $spaIdentifier = $this->argument('spa');
        $status = strtolower($this->argument('status'));

        if (! in_array($status, ['active', 'suspended'], true)) {
            $this->error("Invalid status '{$status}'. Allowed values are: active, suspended.");
            return 1;
        }

        $spa = is_numeric($spaIdentifier)
            ? Spa::find($spaIdentifier)
            : Spa::where('slug', $spaIdentifier)->first();

        if (! $spa) {
            $this->error("Spa '{$spaIdentifier}' not found.");
            return 1;
        }

        $spa->status = $status;
        $spa->save();

        $this->info("Spa '{$spa->name}' ({$spa->slug}) status updated to '{$status}'.");

        return 0;
    }
}
