<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class PageLayout extends Component
{
    public $title;

    public $breadcrumb;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct($title, $breadcrumb)
    {
        $this->title = $title;
        $this->breadcrumb = $breadcrumb;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|\Closure|string
     */
    public function render()
    {
        return view(config('settings.KT_THEME_LAYOUT_DIR').'._page');
    }
}
