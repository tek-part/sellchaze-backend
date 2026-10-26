<?php

namespace App\Core;

class Theme
{
    /**
     * Variables
     *
     * @var bool
     */
    public static $modeSwitchEnabled = false;

    public static $modeDefault = 'light';

    public static $direction = 'ltr';

    public static $htmlAttributes = [];

    public static $htmlClasses = [];

    /**
     * Keep page level assets
     *
     * @var array
     */
    public static $javascriptFiles = [];

    public static $cssFiles = [];

    public static $vendorFiles = [];

    /**
     * Get product name
     *
     * @return void
     */
    public function getName() {}

    /**
     * Add HTML attributes by scope
     *
     *
     * @return void
     */
    public function addHtmlAttribute($scope, $name, $value)
    {
        self::$htmlAttributes[$scope][$name] = $value;
        // dump(self::$htmlAttributes);
    }

    /**
     * Add multiple HTML attributes by scope
     *
     *
     * @return void
     */
    public function addHtmlAttributes($scope, $attributes)
    {
        foreach ($attributes as $key => $value) {
            self::$htmlAttributes[$scope][$key] = $value;
        }
    }

    /**
     * Add HTML class by scope
     *
     *
     * @return void
     */
    public function addHtmlClass($scope, $value)
    {
        self::$htmlClasses[$scope] = $value;
    }

    /**
     * Print HTML attributes for the HTML template
     *
     *
     * @return string
     */
    public function printHtmlAttributes($scope)
    {
        $attributes = [];
        if (isset(self::$htmlAttributes[$scope])) {
            foreach (self::$htmlAttributes[$scope] as $key => $value) {
                $attributes[] = sprintf('%s="%s"', $key, $value);
            }
        }

        return implode(' ', $attributes);
    }

    /**
     * Print HTML classes for the HTML template
     *
     *
     * @return string
     */
    public function printHtmlClasses($scope, $full = true)
    {
        if (empty(self::$htmlClasses)) {
            return '';
        }

        $classes = '';
        if (isset(self::$htmlClasses[$scope])) {
            $classes = self::$htmlClasses[$scope];
        }

        if ($full) {
            return sprintf('class="%s"', $classes);
        }

        return $classes;
    }

    /**
     * Get SVG icon content
     *
     * @param  $folder
     * @return string
     */
    public function getSvgIcon($path, $classNames = 'svg-icon')
    {
        $basePath = config('settings.KT_THEME') === 'rizz' ? 'rizz/media/icons/' : 'assets/media/icons/';
        $fullPath = public_path($basePath.$path);
        if (file_exists($fullPath)) {
            return sprintf('<span class="%s">%s</span>', $classNames, file_get_contents($fullPath));
        }

        return '';
    }

    /**
     * Set dark mode enabled status
     *
     *
     * @return void
     */
    public function setModeSwitch($flag)
    {
        self::$modeSwitchEnabled = $flag;
    }

    /**
     * Check dark mode status
     *
     * @return bool
     */
    public function isModeSwitchEnabled()
    {
        return self::$modeSwitchEnabled;
    }

    /**
     * Set the mode to dark or light
     *
     *
     * @return void
     */
    public function setModeDefault($mode)
    {
        self::$modeDefault = $mode;
    }

    /**
     * Get current mode
     *
     * @return string
     */
    public function getModeDefault()
    {
        return self::$modeDefault;
    }

    /**
     * Set style direction
     *
     *
     * @return void
     */
    public function setDirection($direction)
    {
        self::$direction = $direction;
    }

    /**
     * Get style direction
     *
     * @return string
     */
    public function getDirection()
    {
        return self::$direction;
    }

    /**
     * Check if style direction is RTL
     *
     * @return bool
     */
    public function isRtlDirection()
    {
        return self::$direction === 'rtl';
    }

    /**
     * Extend CSS file name with RTL or dark mode
     *
     *
     * @return string
     */
    public function extendCssFilename($path)
    {
        if ($this->isRtlDirection()) {
            $path = str_replace('.css', '.rtl.css');
        }

        return $path;
    }

    /**
     * Include favicon from settings
     *
     * @return string
     */
    public function includeFavicon()
    {
        return sprintf('<link rel="shortcut icon" href="%s" />', asset(config('settings.KT_THEME_ASSETS.favicon')));
    }

    /**
     * Include the fonts from settings
     *
     * @return string
     */
    public function includeFonts()
    {
        $content = '';

        foreach (config('settings.KT_THEME_ASSETS.fonts') as $url) {
            $content .= sprintf('<link rel="stylesheet" href="%s">', asset($url));
        }

        return $content;
    }

    /**
     * Get the global assets
     *
     * @return array
     */
    public function getGlobalAssets($type = 'js')
    {
        // $this->extendCssFilename()
        return config('settings.KT_THEME_ASSETS.global.'.$type);
    }

    /**
     * Add multiple vendors to the page by name. Refer to settings KT_THEME_VENDORS
     *
     *
     * @return array
     */
    public function addVendors($vendors)
    {
        foreach ($vendors as $value) {
            self::$vendorFiles[] = $value;
        }

        return array_unique(self::$vendorFiles);
    }

    /**
     * Add single vendor to the page by name. Refer to settings KT_THEME_VENDORS
     *
     *
     * @return void
     */
    public function addVendor($vendor)
    {
        self::$vendorFiles[] = $vendor;
    }

    /**
     * Add custom javascript file to the page
     *
     *
     * @return void
     */
    public function addJavascriptFile($file)
    {
        self::$javascriptFiles[] = $file;
    }

    /**
     * Add custom CSS file to the page
     *
     *
     * @return void
     */
    public function addCssFile($file)
    {
        self::$cssFiles[] = $file;
    }

    /**
     * Get vendor files from settings. Refer to settings KT_THEME_VENDORS
     *
     *
     * @return array
     */
    public function getVendors($type)
    {
        $files = [];
        foreach (self::$vendorFiles as $vendor) {
            $vendors = config('settings.KT_THEME_VENDORS.'.$vendor);
            if (isset($vendors[$type])) {
                foreach ($vendors[$type] as $path) {
                    $files[] = $path;
                }
            }
        }

        return array_unique($files);
    }

    /**
     * Get custom js files from the settings
     *
     * @return array
     */
    public function getCustomJs()
    {
        return self::$javascriptFiles;
    }

    /**
     * Get custom css files from the settings
     *
     * @return array
     */
    public function getCustomCss()
    {
        return self::$cssFiles;
    }

    /**
     * Get HTML attribute based on the scope
     *
     *
     * @return array
     */
    public function getHtmlAttribute($scope, $attribute)
    {
        return self::$htmlAttributes[$scope][$attribute] ?? [];
    }
}
