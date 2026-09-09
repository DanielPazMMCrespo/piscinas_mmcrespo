<?php

$worktree = dirname(__DIR__);
$loader = require __DIR__ . '/../vendor/autoload.php';

$map = $loader->getClassMap();
$baseDir = realpath($worktree . '/vendor/..');
$newMap = [];
foreach ($map as $class => $file) {
    $real = realpath($file);
    if ($real && $baseDir && str_starts_with($real, $baseDir)) {
        $rel = substr($real, strlen($baseDir));
        $worktreeFile = $worktree . $rel;
        if (file_exists($worktreeFile)) {
            $newMap[$class] = $worktreeFile;
        }
    }
}
$loader->addClassMap($newMap);

$loader->setPsr4('App\\', [$worktree . '/app']);
$loader->setPsr4('Tests\\', [$worktree . '/tests']);
$loader->setPsr4('Database\\Seeders\\', [$worktree . '/database/seeders']);
$loader->setPsr4('Database\\Factories\\', [$worktree . '/database/factories']);

$_ENV['APP_BASE_PATH'] = $worktree;
$_SERVER['APP_BASE_PATH'] = $worktree;