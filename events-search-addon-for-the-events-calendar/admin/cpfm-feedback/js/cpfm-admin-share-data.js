jQuery(function($) {
    
    const $termsLink         = $('.ecsa-see-terms');
    const $termsBox          = $('.ecsa-terms-box');

    $termsLink.on('click', function(e) {

        e.preventDefault();
        
        const isVisible = $termsBox.toggle().is(':visible');
        $(this).html(isVisible ? 'Hide Terms' : 'See terms');
    });


});
