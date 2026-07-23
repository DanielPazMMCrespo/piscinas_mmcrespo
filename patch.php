<?php

$models = [
    'app/Models/Installation.php',
    'app/Models/OperationalAction.php',
    'app/Models/Product.php',
    'app/Models/StockInstallation.php',
    'app/Models/StockWarehouse.php',
    'app/Models/HannaDevice.php',
];

$imports = <<<EOT
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
EOT;

$method = <<<'EOT'

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
EOT;

foreach ($models as $file) {
    if (! file_exists($file)) {
        continue;
    }
    $content = file_get_contents($file);
    if (strpos($content, 'LogsActivity') !== false) {
        continue;
    }

    // Add imports before the class declaration
    $content = preg_replace('/(class [a-zA-Z0-9_]+ extends Model)/', $imports."\n\n$1", $content);

    // Add trait
    if (strpos($content, 'use HasFactory;') !== false) {
        $content = str_replace('use HasFactory;', 'use HasFactory, LogsActivity;', $content);
    } else {
        $content = preg_replace('/(class [a-zA-Z0-9_]+ extends Model\s*\{)/', "$1\n    use LogsActivity;\n", $content);
    }

    // Add method before last brace
    $content = preg_replace('/\}\s*$/', $method."\n}\n", $content);

    file_put_contents($file, $content);
    echo "Patched $file\n";
}
