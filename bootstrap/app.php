<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin.privilege' => \App\Http\Middleware\CheckAdminPrivilege::class,
        ]);

        // Runs on every API route. It only acts on students who still have a temporary password
        $middleware->appendToGroup('api', \App\Http\Middleware\EnsurePasswordIsUpdated::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
