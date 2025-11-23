<?php

use App\Console\Commands\NexusChatCommand;
use App\Console\Commands\NexusPipelineCommand;
use App\Console\Commands\NexusSetupCommand;
use App\Console\Commands\SandboxStartFreshCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        NexusPipelineCommand::class,
        NexusChatCommand::class,
        NexusSetupCommand::class,
        SandboxStartFreshCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
