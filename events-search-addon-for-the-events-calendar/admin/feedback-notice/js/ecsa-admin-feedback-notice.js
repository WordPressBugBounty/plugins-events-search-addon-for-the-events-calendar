jQuery(document).ready(function ($) {
	$('.ecsa_dismiss_notice').on('click', function (event) {
		var $this = $(this);
		var wrapper=$this.parents('.cool-feedback-notice-wrapper');
		var ajaxURL=wrapper.data('ajax-url');
		var ajaxCallback=wrapper.data('ajax-callback');
		var nonce=wrapper.data('nonce');
		
		$.post(ajaxURL, { 
            'action': ajaxCallback,
            'security': nonce
        }, function(data) {
            if (data.success) {
                wrapper.slideUp('fast', function () {
					$(this).remove(); // completely remove from DOM
				});
            }
        }, "json");

	});
});