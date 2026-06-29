<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

$serveFrontend = function () {
    $frontendIndex = base_path('index.html');

    if (! File::exists($frontendIndex)) {
        $frontendIndex = public_path('index.html');
    }

    if (File::exists($frontendIndex)) {
        return response()->file($frontendIndex);
    }

    return response()->json([
        'app' => 'Tema Litoclean Backend',
        'status' => 'ok',
        'mode' => 'api',
    ]);
};

Route::get('/', $serveFrontend);

Route::get('/{path}', $serveFrontend)
    ->where('path', '^(?!api(?:/|$)|up$).*$');
