<?php

use App\Services\PackageImporter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('lesson:import {path}', function (PackageImporter $importer) {
    $result = $importer->import($this->argument('path'));
    $this->info(($result['duplicate'] ? 'Existing' : 'Imported').' lesson #'.$result['lesson']->id);
    $this->line(json_encode($result['lesson']->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
