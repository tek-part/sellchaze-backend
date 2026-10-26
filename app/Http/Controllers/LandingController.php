<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class LandingController extends Controller
{
    /**
     * Show the landing page for guests; redirect authenticated users to dashboard.
     *
     * @return View|RedirectResponse
     */
    public function index()
    {
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        if (! session()->has('locale')) {
            session(['locale' => 'en']);
            App::setLocale('en');
        }

        $latestArticles = Article::published()
            ->latest('published_at')
            ->take(3)
            ->get();

        return view('pages.landing.index', compact('latestArticles'));
    }
}
