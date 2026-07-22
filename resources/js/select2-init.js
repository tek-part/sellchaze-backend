/*
 |--------------------------------------------------------------------------
 | Select2 initialisation - Rizz theme
 |--------------------------------------------------------------------------
 | Search-box policy: a select only gets a search input when its option list
 | is long enough to be worth searching. Below the threshold the search box is
 | suppressed, because scanning a short list beats typing into it.
 |
 | Relies on the global jQuery loaded in layout/rizz/master.blade.php.
 */

(function ($) {
    'use strict';

    if (!$ || !$.fn || !$.fn.select2) {
        return;
    }

    // Option count at or above which the search input appears.
    var SEARCH_THRESHOLD = 10;

    // Both attribute conventions are present in the views.
    var SELECTOR = 'select[data-control="select2"], select[data-kt-select2="true"]';

    /**
     * Count real choices, ignoring the empty placeholder <option></option>
     * that the theme markup puts first.
     */
    function countOptions($el) {
        return $el.find('option').filter(function () {
            return this.value !== '' && this.value !== null;
        }).length;
    }

    function build($el) {
        var placeholder = $el.data('placeholder') || '';

        var options = {
            width: '100%',
            placeholder: placeholder,
            // allowClear needs a placeholder to clear back to, or select2 throws.
            allowClear: $el.data('allow-clear') === true && placeholder !== '',
            minimumResultsForSearch: countOptions($el) >= SEARCH_THRESHOLD ? 0 : Infinity,
        };

        // Keep the dropdown inside its modal, otherwise it renders clipped
        // behind the backdrop and the search input cannot take focus.
        var $modal = $el.closest('.modal');
        if ($modal.length) {
            options.dropdownParent = $modal;
        }

        return options;
    }

    /**
     * Initialise every uninitialised select2 within `context`.
     * Safe to call repeatedly.
     */
    function init(context) {
        $(SELECTOR, context || document).each(function () {
            var $el = $(this);

            if ($el.data('select2')) {
                return;
            }

            $el.select2(build($el));
        });
    }

    $(function () {
        init(document);
    });

    // Markup injected after load: modals, AJAX-loaded partials.
    $(document).on('shown.bs.modal', function (event) {
        init(event.target);
    });

    // Exposed so views that inject rows (repeaters, dynamic line items)
    // can re-run it: window.initSelect2(newRowElement)
    window.initSelect2 = init;
})(window.jQuery);
