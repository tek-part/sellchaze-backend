<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class HomeController extends Controller
{
    /**
     * Show the application dashboard (redirect to main dashboard).
     *
     * @return RedirectResponse
     */
    public function index()
    {
        return redirect()->route('dashboard');
    }
}
