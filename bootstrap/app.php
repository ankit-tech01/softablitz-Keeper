<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\ErrorHandler\Error\FatalError;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $exception instanceof FatalError
                || ! str_contains(strtolower($exception->getMessage()), 'maximum execution time')) {
                return null;
            }

            $message = 'This task exceeded the time limit and was interrupted.';
            $nextSteps = [
                'Try the task again with a smaller folder or fewer files.',
                'Close other demanding apps and retry.',
                'If this keeps happening, restart Keeper and check the task status before trying again.',
            ];

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'next_steps' => $nextSteps,
                ], 500);
            }

            return response()->view('errors.timeout', [
                'message' => $message,
                'nextSteps' => $nextSteps,
            ], 500);
        });
    })->create();
