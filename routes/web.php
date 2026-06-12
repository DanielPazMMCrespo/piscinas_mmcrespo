<?php

use Illuminate\Support\Facades\Route;

// A app é o painel Filament — a raiz vai direta para lá.
Route::redirect('/', '/admin');
