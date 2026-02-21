/**
 * Events Addons Dashboard Script
 * Modular JavaScript file - Can be used in any plugin
 * 
 * Dependencies: jQuery
 * Requires: cp_events object with ajax_url and plugin_tag properties
 */
(function ($) {

    function installPlugin(btn, slugg) {

        let button = $(btn);
        const slug = slugg;

        const allowedSlugs = [
            "event-page-templates-addon-for-the-events-calendar",
            "events-block-for-the-events-calendar",
            "events-search-addon-for-the-events-calendar",
            "template-events-calendar",
            "events-widgets-for-elementor-and-the-events-calendar",
            "countdown-for-the-events-calendar",
            "events-calendar-modules-for-divi",
            "the-events-calendar-templates-and-shortcode",
            "events-widgets-pro",
            "event-single-page-builder-pro",
            "cp-events-calendar-modules-for-divi-pro",
            "events-speakers-and-sponsors",
        ];

        if (!slug || allowedSlugs.indexOf(slug) === -1) return;

        // // Get the nonce from the button data attribute
        let nonce = button.data('nonce');

        // Check if button is for activation or installation
        const isActivation = button.hasClass('ect-btn-activate') || button.text().trim().toLowerCase().includes('activate');
        const loadingText = isActivation ? 'Activating...' : 'Installing...';

        button.text(loadingText).prop('disabled', true);
        disableAllOtherPluginButtonsTemporarily(slug);

        $.post(ajaxurl, {

            action: 'ect_dashboard_install_plugin',
            slug: slug,
            _wpnonce: nonce
        },

            function (response) {

                const pluginSlug = slug;
                const responseString = JSON.stringify(response);
                const responseContainsPlugin = responseString.includes(pluginSlug) || (response.success && response.data && response.data.plugin_slug === pluginSlug);

                if (responseContainsPlugin) {
                    const isActivated = (response.data && response.data.activated) || responseString.includes(pluginSlug);

                    if (isActivated) {
                        button.text('Activated Successfully');
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        // Installed but not activated
                        button.text('Activate Now');
                        button.addClass('ect-btn-activate');
                        button.prop('disabled', false);

                        // Re-enable other buttons
                        jQuery('.ect-install-plugin').prop('disabled', false);
                    }
                } else {
                    // Reset button state
                    button.text(isActivation ? 'Activate Now' : 'Install Now').prop('disabled', false);
                    jQuery('.ect-install-plugin').prop('disabled', false);

                    let errorMessage = 'Error: Please try again or <a href="https://wordpress.org/plugins/' + slug + '/" target="_blank">download manually</a>.';

                    // Check for specific error message from server
                    if (response.data && (response.data.message || response.data.errorMessage)) {
                        errorMessage = response.data.message || response.data.errorMessage;
                    } else if (response.data && typeof response.data === 'string') {
                        // Sometimes the data itself is the message
                        errorMessage = response.data;
                    }

                    // Show error message below button
                    let $errorDiv = button.next('.ect-error-message');

                    if (!$errorDiv.length) {
                        $errorDiv = $('<div class="ect-error-message"></div>');
                        button.after($errorDiv);
                    }

                    showMessage($errorDiv, errorMessage);

                    const $globalNotice = $('.ect-notice-widget');
                    if ($globalNotice.length) {
                        showMessage($globalNotice, errorMessage);
                    }
                }
            });
    }

    function showMessage($element, message, timeout = 5000) {
        if (!$element.length) return;
    
        $element.html(message).show();
    
        setTimeout(function () {
            $element.fadeOut(500, function () {
                $(this).remove();
            });
        }, timeout);
    }

    function disableAllOtherPluginButtonsTemporarily(activeSlug) {

        jQuery('.ect-install-plugin').each(function () {
            const $btn = jQuery(this);
            const btnSlug = $btn.data('slug');
            if (btnSlug !== activeSlug) {
                $btn.prop('disabled', true);
            }
        });
    }

    $(document).ready(function ($) {

        const customNotice = $('.ect-cards-container');
        if (customNotice.length === 0) return;

        const installBtns = customNotice.find('button.ect-install-plugin, a.ect-install-plugin');

        if (installBtns.length === 0) return;

        installBtns.each(function () {

            const btn = this;
            const installSlug = btn.dataset.slug;
            $(btn).on('click', function () {
                if (installSlug) {
                    installPlugin($(btn), installSlug);
                }
            });
        });
    })
})(jQuery);