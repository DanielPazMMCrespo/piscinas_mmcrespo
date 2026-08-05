<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $w = app(App\Filament\Widgets\PainelPiscinasWidget::class);
    $r = new ReflectionMethod($w, 'buildPoolData');
    $r->setAccessible(true);
    $r->invoke($w);
    echo "OK\n";
} catch (\Throwable $e) {
    echo $e;
}
