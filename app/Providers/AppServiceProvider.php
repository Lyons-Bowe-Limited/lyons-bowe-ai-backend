<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use App\Mail\GraphTransport;
use App\Services\GraphMailService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Mail::extend('graph', function () {
            return new GraphTransport(
                app(GraphMailService::class)
            );
        });
    }
}