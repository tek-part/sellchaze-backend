<?php

namespace App\Http\Controllers;

class GrowtechPageController extends Controller
{
    public function about()
    {
        return view('pages.growtech.about', [
            'bodyClass' => 'about-v1',
            'pageCss' => 'about-v1.css',
        ]);
    }

    public function contact()
    {
        return view('pages.growtech.contact', [
            'bodyClass' => 'contact',
            'pageCss' => 'contact.css',
        ]);
    }

    public function faq()
    {
        return view('pages.growtech.faq', [
            'bodyClass' => 'faq',
            'pageCss' => 'faq.css',
        ]);
    }

    public function faqDetails()
    {
        return view('pages.growtech.faq-details', [
            'bodyClass' => 'faq-details',
            'pageCss' => 'faq-details.css',
        ]);
    }

    public function helpCenter()
    {
        return view('pages.growtech.help-center', [
            'bodyClass' => 'help',
            'pageCss' => 'help-center.css',
        ]);
    }

    public function notFound()
    {
        return response()->view('pages.growtech.404', [
            'bodyClass' => 'not-found',
            'pageCss' => '404.css',
            'navbarFullScreen' => true,
            'showFooter' => false,
        ], 404);
    }

    public function comingSoon()
    {
        return view('pages.growtech.coming-soon', [
            'bodyClass' => 'coming-soon',
            'pageCss' => 'coming-soon.css',
            'navbarFullScreen' => true,
            'showFooter' => false,
        ]);
    }

    public function changelog()
    {
        return view('pages.growtech.changelog', [
            'bodyClass' => 'change-log',
            'pageCss' => 'changelog.css',
        ]);
    }

    public function emailConfirmation()
    {
        return view('pages.growtech.email-confirmation', [
            'bodyClass' => 'email-confirmation',
            'pageCss' => 'email-confirmation.css',
            'navbarFullScreen' => true,
            'showFooter' => false,
        ]);
    }

    public function styleGuide()
    {
        return view('pages.growtech.style-guide', [
            'bodyClass' => 'style-guide',
            'pageCss' => 'style-guide.css',
        ]);
    }
}
