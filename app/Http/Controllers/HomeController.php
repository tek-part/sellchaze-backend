<?php

namespace App\Http\Controllers;

class HomeController extends Controller
{
    /**
     * Show the application dashboard (redirect to main dashboard).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function index()
    {
        return redirect()->route('dashboard');
    }
}