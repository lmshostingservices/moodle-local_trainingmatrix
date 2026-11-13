// Moodle training cards. GPL v3 or later; copyright 2026 LMS-Labs.
// Native progressive enhancement; details/summary remains usable without JS.
(function() {
    'use strict';
    function init() {
        document.querySelectorAll('[data-tmx-root]').forEach(function(root) {
            if (root.dataset.tmxReady) {
                return;
            }
            root.dataset.tmxReady = '1';
            // Size classes for the card area (replaces CSS container queries, which Moodle's CSS checks reject).
            // tmx-wN is set while the card area is N px wide or less; --tmx-cqi is 1% of its width.
            var sizes = [900, 620, 520, 380];
            var resize = function(width) {
                sizes.forEach(function(size) {
                    root.classList.toggle('tmx-w' + size, width <= size);
                });
                root.style.setProperty('--tmx-cqi', (width / 100) + 'px');
            };
            if (window.ResizeObserver) {
                new ResizeObserver(function(entries) {
                    resize(entries[0].contentRect.width);
                }).observe(root);
            } else {
                var measure = function() {
                    var style = window.getComputedStyle(root);
                    resize(root.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight));
                };
                window.addEventListener('resize', measure);
                measure();
            }
            var toolbar = root.querySelector('.tmx-toolbar');
            toolbar.hidden = false;
            var search = root.querySelector('[data-tmx-search]');
            var filter = root.querySelector('[data-tmx-filter]');
            var teacher = root.classList.contains('tmx-teacher');
            var rows = Array.from(root.querySelectorAll('[data-tmx-row="' + (teacher ? 'staff' : 'item') + '"]'));
            function update() {
                var shown = 0;
                rows.forEach(function(row) {
                    var matches = row.textContent.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()) &&
                        (filter.value === 'all' || row.dataset.tmxStates.split(' ').includes(filter.value));
                    row.hidden = !matches;
                    shown += matches ? 1 : 0;
                });
                var counter = root.querySelector('[data-tmx-count]');
                counter.textContent = counter.dataset.tmxLabel.replace('{$a->shown}', shown)
                    .replace('{$a->total}', rows.length) + (shown ? '' : ' — ' + counter.dataset.tmxNomatch);
            }
            search.addEventListener('input', update);
            filter.addEventListener('change', update);
            root.querySelector('[data-tmx-density]').addEventListener('click', function(event) {
                var compact = root.classList.toggle('is-compact');
                event.currentTarget.setAttribute('aria-pressed', String(compact));
            });
            update();
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
