<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Read-only training for the authenticated learner.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/trainingmatrix:view', $context);

$search = trim(optional_param('search', '', PARAM_TEXT));
$kind = optional_param('kind', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$kinds = \local_trainingmatrix\output\learner_view::kinds();
if (!array_key_exists($kind, $kinds)) {
    $kind = '';
}

$url = new moodle_url('/local/trainingmatrix/my.php', ['search' => $search, 'kind' => $kind]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('learner_mytraining', 'local_trainingmatrix'));
$PAGE->set_heading(get_string('learner_mytraining', 'local_trainingmatrix'));

// The service authenticates the current user; no user identifier is accepted here.
$data = \local_trainingmatrix\learner_training::get();
$items = array_values(array_filter($data['items'], static function (array $item) use ($search, $kind): bool {
    if ($kind !== '' && $item['kind'] !== $kind) {
        return false;
    }
    if ($search === '') {
        return true;
    }
    foreach (['name', 'source', 'status'] as $field) {
        if (core_text::strpos(core_text::strtolower((string)$item[$field]),
                core_text::strtolower($search)) !== false) {
            return true;
        }
    }
    return false;
}));
$total = count($items);
$perpage = 20;
$page = min($page, max(0, (int)ceil($total / $perpage) - 1));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('learner_mytraining', 'local_trainingmatrix'));
echo html_writer::tag('p', s(get_string('learner_intro', 'local_trainingmatrix')), ['class' => 'text-muted']);
echo \local_trainingmatrix\output\learner_view::notices($data['notices']);

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/trainingmatrix/my.php'))->out(false),
    'class' => 'mb-4',
    'role' => 'search',
    'aria-label' => get_string('learner_filtertraining', 'local_trainingmatrix'),
]);
echo html_writer::start_div('row');
echo html_writer::start_div('col-md-7 mb-3');
echo html_writer::tag('label', s(get_string('learner_search', 'local_trainingmatrix')),
    ['for' => 'training-search']);
echo html_writer::empty_tag('input', [
    'type' => 'search', 'id' => 'training-search', 'name' => 'search', 'value' => $search,
    'class' => 'form-control', 'aria-describedby' => 'training-search-help',
]);
echo html_writer::tag('small', s(get_string('learner_searchhelp', 'local_trainingmatrix')),
    ['id' => 'training-search-help', 'class' => 'form-text text-muted']);
echo html_writer::end_div();
echo html_writer::start_div('col-md-5 mb-3');
echo html_writer::tag('label', s(get_string('learner_kind', 'local_trainingmatrix')),
    ['for' => 'training-kind']);
echo html_writer::select($kinds, 'kind', $kind,
    ['' => get_string('learner_allkinds', 'local_trainingmatrix')],
    ['id' => 'training-kind', 'class' => 'form-control']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::tag('button', s(get_string('learner_applyfilters', 'local_trainingmatrix')),
    ['type' => 'submit', 'class' => 'btn btn-primary mr-2']);
echo html_writer::link(new moodle_url('/local/trainingmatrix/my.php'),
    s(get_string('learner_clearfilters', 'local_trainingmatrix')), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (!$items) {
    $emptykey = empty($data['items']) ? 'learner_empty' : 'learner_noresults';
    echo $OUTPUT->notification(s(get_string($emptykey, 'local_trainingmatrix')), 'info');
} else {
    $range = (object)[
        'start' => $page * $perpage + 1,
        'end' => min(($page + 1) * $perpage, $total),
        'total' => $total,
    ];
    echo html_writer::tag('p', s(get_string('learner_resultcount', 'local_trainingmatrix', $range)));
    echo \local_trainingmatrix\output\learner_view::table(array_slice($items, $page * $perpage, $perpage));
    echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
}

echo $OUTPUT->footer();
