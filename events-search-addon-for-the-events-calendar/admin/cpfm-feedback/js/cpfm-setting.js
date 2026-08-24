jQuery(document).ready(function($) {
    $('#ecsa-cpfm-data-sharing').on('change', function() {
        let isChecked = $(this).is(':checked') ? 'yes' : 'no';
        $.post(ajaxurl, {
            action: 'cpfm_save_usage_data_sharing',
            opt_in: isChecked,
            plugin: 'ecsa',
            nonce: cpfm_ajax_obj.nonce
        });
    });
});
