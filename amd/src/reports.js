/**
 * Training Matrix HCM v1.0.0 - Reports AMD module
 *
 * @module     local_trainingmatrix/reports
 * @package    local_trainingmatrix
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/notification'], function($, Notification) {
    'use strict';

    return {
        init: function() {
            this.bindExport();
            this.bindDateRange();
            this.bindPrint();
        },

        bindExport: function() {
            $('#tm-export-csv').on('click', function(e) {
                e.preventDefault();
                window.location.href = M.cfg.wwwroot + '/local/trainingmatrix/ajax.php?' + $.param({
                    action: 'export',
                    format: 'csv',
                    sesskey: M.cfg.sesskey
                });
            });

            $('#tm-export-excel').on('click', function(e) {
                e.preventDefault();
                window.location.href = M.cfg.wwwroot + '/local/trainingmatrix/ajax.php?' + $.param({
                    action: 'export',
                    format: 'excel',
                    sesskey: M.cfg.sesskey
                });
            });
        },

        bindDateRange: function() {
            var self = this;
            $('[name="report_from"], [name="report_to"]').on('change', function() {
                self.refreshReport();
            });
        },

        refreshReport: function() {
            var from = $('[name="report_from"]').val();
            var to = $('[name="report_to"]').val();
            var department = $('[name="filter_department"]').val();
            var type = $('[name="report_type"]').val();

            var $container = $('.tm-report-container');
            $container.addClass('loading');

            $.ajax({
                url: M.cfg.wwwroot + '/local/trainingmatrix/ajax.php',
                method: 'GET',
                data: {
                    action: 'getreport',
                    from: from,
                    to: to,
                    department: department,
                    type: type,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json'
            }).done(function(data) {
                if (data.ok && data.html) {
                    $container.html(data.html);
                }
            }).fail(function() {
                Notification.exception({message: 'Failed to load report'});
            }).always(function() {
                $container.removeClass('loading');
            });
        },

        bindPrint: function() {
            $('#tm-print-report').on('click', function(e) {
                e.preventDefault();
                window.print();
            });
        }
    };
});
