<?php

namespace App\View\Components;

use Illuminate\View\Component;

class ApplicationLogo extends Component
{
    /**
     * Get the view / contents that represents the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view(config('settings.KT_THEME_LAYOUT_DIR').'._logo');
    }
}
