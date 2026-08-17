<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Production runs behind Cloudflare Tunnel, which terminates HTTPS at the edge
        // and forwards to this app over plain HTTP on 127.0.0.1. Without trusting that
        // loopback hop, Laravel has no way to know the original request was HTTPS, so
        // every absolute URL it generates (route(), redirect()->intended(), the Inertia
        // form `action` values) comes out as http:// even on an https:// page — browsers
        // then block those as mixed content, which looks like "nothing happens" on submit.
        $middleware->trustProxies(
            at: '127.0.0.1',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
