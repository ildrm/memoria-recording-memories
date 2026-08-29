<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        if (! app()->isProduction()) {
            return response(
                "User-agent: *\nDisallow: /\n",
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return response(
            "User-agent: *\nAllow: /\nDisallow: /app\nDisallow: /admin\nDisallow: /shares\nSitemap: ".route('sitemap')."\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
