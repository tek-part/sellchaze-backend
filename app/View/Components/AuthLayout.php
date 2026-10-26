<?php

namespace App\View\Components;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AuthLayout extends Component
{
    public ?string $bodyClass = null;

    public ?string $pageCss = null;

    public ?string $formTitle = null;

    public ?string $formSubtitle = null;

    public function __construct(
        string $bodyClass = 'sign-in',
        string $pageCss = 'sign-in.css',
        ?string $formTitle = null,
        ?string $formSubtitle = null
    ) {
        $this->bodyClass = $bodyClass;
        $this->pageCss = $pageCss;
        $this->formTitle = $formTitle;
        $this->formSubtitle = $formSubtitle;
    }

    /**
     * Get the view / contents that represents the component.
     *
     * @return Application|Factory|View
     */
    public function render()
    {
        $layoutDir = config('settings.FRONTEND_THEME', 'rizz') === 'growtech'
            ? 'layout.growtech'
            : config('settings.KT_THEME_LAYOUT_DIR');
        $data = config('settings.FRONTEND_THEME', 'rizz') === 'growtech'
            ? [
                'bodyClass' => $this->bodyClass,
                'pageCss' => $this->pageCss,
                'navbarFullScreen' => true,
                'showFooter' => false,
                'formTitle' => $this->formTitle,
                'formSubtitle' => $this->formSubtitle,
            ]
            : [];

        return view($layoutDir.'._auth', $data);
    }
}
