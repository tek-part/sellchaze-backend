<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class LandingLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     *
     * @return View
     */
    public function render()
    {
        $layoutDir = config('settings.FRONTEND_THEME', 'rizz') === 'growtech'
            ? 'layout.growtech'
            : config('settings.KT_THEME_LAYOUT_DIR');

        return view($layoutDir.'._landing');
    }
}
