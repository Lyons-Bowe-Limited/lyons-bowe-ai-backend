<?php

namespace App\Providers;

use App\Mail\GraphTransport;
use App\Services\GraphMailService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('staff-invitations', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('staff-login', function (Request $request) {
            return Limit::perMinute(5)->by(
                mb_strtolower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        Mail::extend('graph', function () {
            return new GraphTransport(
                app(GraphMailService::class)
            );
        });
    }
}
