<?php

namespace App\View\Components;

use Illuminate\View\Component;

class LandingLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function render()
    {
        $layoutDir = config('settings.FRONTEND_THEME', 'rizz') === 'growtech'
            ? 'layout.growtech'
            : config('settings.KT_THEME_LAYOUT_DIR');
        return view($layoutDir.'._landing');
    }
}
