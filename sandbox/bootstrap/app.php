<?php

use App\Console\Commands\InveloChatCommand;
use App\Console\Commands\ListChatsCommand;
use App\Console\Commands\LogChatCommand;
use App\Console\Commands\NexusChatCommand;
use App\Console\Commands\NexusPipelineCommand;
use App\Console\Commands\PrismChatCommand;
use App\Console\Commands\PrismTextCommand;
use App\Console\Commands\ViewChatCommand;
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
        PrismTextCommand::class,
        PrismChatCommand::class,
        LogChatCommand::class,
        ListChatsCommand::class,
        ViewChatCommand::class,
        InveloChatCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
