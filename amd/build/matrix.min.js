/**
 * Training Matrix HCM v1.0.0 - Matrix view AMD module
 *
 * @module     local_trainingmatrix/matrix
 * @package    local_trainingmatrix
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('local_trainingmatrix/matrix', ['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    'use strict';

    return {
        init: function() {
            this.bindRowExpand();
            this.bindFilters();
            this.bindSort();
        },

        bindRowExpand: function() {
            $('.tm-table').on('click', '.tm-row-expandable', function() {
                var $row = $(this);
                var $details = $row.next('.tm-row-details');
                
                $row.toggleClass('expanded');
                $details.slideToggle(200);
            });
        },

        bindFilters: function() {
            var self = this;
            $('.tm-filter-select').on('change', function() {
                self.applyFilters();
            });

            var timeout;
            $('.tm-search-input').on('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    self.applyFilters();
                }, 300);
            });
        },

        applyFilters: function() {
            var department = $('[name="filter_department"]').val();
            var position = $('[name="filter_position"]').val();
            var status = $('[name="filter_status"]').val();
            var search = $('.tm-search-input').val().toLowerCase();

            $('.tm-table tbody tr').each(function() {
                var $row = $(this);
                var show = true;

                if (department && $row.data('department') != department) {
                    show = false;
                }
                if (position && $row.data('position') != position) {
                    show = false;
                }
                if (status && $row.data('status') != status) {
                    show = false;
                }
                if (search) {
                    var text = $row.text().toLowerCase();
                    if (text.indexOf(search) === -1) {
                        show = false;
                    }
                }

                $row.toggle(show);
            });

            this.updateCounts();
        },

        bindSort: function() {
            var self = this;
            $('.tm-table th.sortable').on('click', function() {
                var $th = $(this);
                var column = $th.data('column');
                var order = $th.hasClass('asc') ? 'desc' : 'asc';

                $('.tm-table th').removeClass('asc desc');
                $th.addClass(order);

                self.sortTable(column, order);
            });
        },

        sortTable: function(column, order) {
            var $tbody = $('.tm-table tbody');
            var $rows = $tbody.find('tr').not('.tm-row-details').get();

            $rows.sort(function(a, b) {
                var aVal = $(a).find('[data-sort="' + column + '"]').text();
                var bVal = $(b).find('[data-sort="' + column + '"]').text();

                if ($.isNumeric(aVal) && $.isNumeric(bVal)) {
                    return order === 'asc' ? aVal - bVal : bVal - aVal;
                }
                return order === 'asc' ? 
                    aVal.localeCompare(bVal) : 
                    bVal.localeCompare(aVal);
            });

            $.each($rows, function(idx, row) {
                $tbody.append(row);
            });
        },

        updateCounts: function() {
            var visible = $('.tm-table tbody tr:visible').not('.tm-row-details').length;
            var total = $('.tm-table tbody tr').not('.tm-row-details').length;
            $('.tm-results-count').text('Showing ' + visible + ' of ' + total);
        }
    };
});
