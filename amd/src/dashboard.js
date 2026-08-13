/**
 * Training Matrix HCM v1.0.0 - Dashboard AMD module
 *
 * @module     local_trainingmatrix/dashboard
 * @package    local_trainingmatrix
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    'use strict';

    return {
        init: function() {
            this.bindRefresh();
            this.bindQuickActions();
        },

        bindRefresh: function() {
            var self = this;
            $('[data-tm-refresh]').on('click', function(e) {
                e.preventDefault();
                self.refreshStats();
            });
        },

        refreshStats: function() {
            var $cards = $('.tm-stat-card');
            $cards.css('opacity', '0.5');

            $.ajax({
                url: M.cfg.wwwroot + '/local/trainingmatrix/ajax.php',
                method: 'GET',
                data: {
                    action: 'getstats',
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json'
            }).done(function(data) {
                if (data.ok) {
                    $('[data-stat="total_staff"]').text(data.stats.total_staff);
                    $('[data-stat="fully_compliant"]').text(data.stats.fully_compliant);
                    $('[data-stat="expiring_soon"]').text(data.stats.expiring_soon);
                    $('[data-stat="expired"]').text(data.stats.expired);
                }
                $cards.css('opacity', '1');
            }).fail(function() {
                Notification.exception({message: 'Failed to refresh stats'});
                $cards.css('opacity', '1');
            });
        },

        bindQuickActions: function() {
            $('[data-tm-action="send-reminder"]').on('click', function(e) {
                e.preventDefault();
                var completionId = $(this).data('completion-id');
                if (completionId) {
                    $.ajax({
                        url: M.cfg.wwwroot + '/local/trainingmatrix/ajax.php',
                        method: 'POST',
                        data: {
                            action: 'sendreminder',
                            completionid: completionId,
                            sesskey: M.cfg.sesskey
                        },
                        dataType: 'json'
                    }).done(function(data) {
                        if (data.ok) {
                            Notification.addNotification({
                                message: 'Reminder sent successfully',
                                type: 'success'
                            });
                        } else {
                            Notification.exception({message: data.error || 'Failed to send reminder'});
                        }
                    });
                }
            });
        }
    };
});
