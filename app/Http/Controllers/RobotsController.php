<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class RobotsController extends Controller
{
    public function index(): Response
    {
        $content = "User-agent: *\nDisallow:\n\nSitemap: " . url('/sitemap.xml') . "\n";

        return response($content, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }
}
