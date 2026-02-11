/**
 * Admin Scripts for WC Memberships - Expire on Dec 31
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Save settings via AJAX
        $('#wcm-dec31-settings-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $button = $('#wcm-dec31-save-settings');
            var originalText = $button.text();

            $button.prop('disabled', true).text(wcmDec31.strings.processing);

            var settings = {
                enabled: $('#wcm_dec31_enabled').is(':checked') ? 1 : 0,
                excluded_plans: $('#wcm_dec31_excluded_plans').val() || []
            };

            $.ajax({
                url: wcmDec31.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wcm_dec31_save_settings',
                    nonce: wcmDec31.nonce,
                    settings: settings
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                    } else {
                        showMessage(response.data.message || wcmDec31.strings.error, 'error');
                    }
                },
                error: function() {
                    showMessage(wcmDec31.strings.error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        // Realign memberships
        $('#wcm-dec31-realign-button').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var year = $button.data('year');
            var affectedCount = $('#wcm-dec31-affected-count').text();

            var confirmMessage = wcmDec31.strings.confirmRealign.replace('%s', year) + '\n\n' +
                                 'Affected memberships: ' + affectedCount;

            if (!confirm(confirmMessage)) {
                return;
            }

            $button.prop('disabled', true);
            var $progress = $('#wcm-dec31-realign-progress');
            var $progressFill = $progress.find('.progress-fill');
            var $progressText = $progress.find('.progress-text');
            var $messages = $('#wcm-dec31-messages');

            $progress.show();
            $messages.empty();
            $progressFill.css('width', '10%');
            $progressText.text(wcmDec31.strings.processing);

            $.ajax({
                url: wcmDec31.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wcm_dec31_realign_memberships',
                    nonce: wcmDec31.nonce,
                    year: year
                },
                success: function(response) {
                    if (response.success) {
                        $progressFill.css('width', '100%');
                        $progressText.text(response.data.message);
                        showMessage(response.data.message, 'success');
                        
                        // Update affected count
                        setTimeout(function() {
                            // Reload page to get fresh count
                            location.reload();
                        }, 2000);
                    } else {
                        $progressFill.css('width', '0%');
                        $progressText.text('');
                        showMessage(response.data.message || wcmDec31.strings.realignError, 'error');
                    }
                },
                error: function() {
                    $progressFill.css('width', '0%');
                    $progressText.text('');
                    showMessage(wcmDec31.strings.realignError, 'error');
                },
                complete: function() {
                    setTimeout(function() {
                        $button.prop('disabled', false);
                    }, 1000);
                }
            });
        });

        /**
         * Show message
         */
        function showMessage(message, type) {
            var $messages = $('#wcm-dec31-messages');
            var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            var $notice = $('<div class="notice ' + noticeClass + '"><p>' + message + '</p></div>');
            
            $messages.empty().append($notice);
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        }
    });

})(jQuery);

