param([ValidateSet('setup','serve','test','import')][string]$Action='serve', [string]$Package)
$ErrorActionPreference='Stop'
$env:PHP_INI_SCAN_DIR = Join-Path $PSScriptRoot 'php'
$phpArgs=@()
switch($Action) {
    'setup' {
        if (!(Test-Path .env)) { Copy-Item .env.example .env }
        if (!(Test-Path database/database.sqlite)) { New-Item database/database.sqlite -ItemType File | Out-Null }
        & php @phpArgs artisan key:generate
        & php @phpArgs artisan migrate --seed --force
    }
    'serve' { & php @phpArgs artisan serve --host=127.0.0.1 --port=8000 }
    'test' { & php @phpArgs artisan test }
    'import' { if (!$Package) { throw 'Supply -Package with a ZIP path.' }; & php @phpArgs artisan lesson:import $Package }
}
