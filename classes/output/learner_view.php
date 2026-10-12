<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Native Moodle presentation for read-only learner training.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_trainingmatrix\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Stateless presentation shared by the learner page and dashboard block.
 */
class learner_view {
    /**
     * Supported kinds with localised labels.
     *
     * @return array
     */
    public static function kinds(): array {
        return [
            'course' => get_string('learner_course', 'local_trainingmatrix'),
            'program' => get_string('learner_program', 'local_trainingmatrix'),
            'certification' => get_string('learner_certification', 'local_trainingmatrix'),
            'certificate' => get_string('learner_certificate', 'local_trainingmatrix'),
        ];
    }

    /**
     * Render service notices as escaped text, not trusted HTML.
     *
     * @param array $notices Service notices.
     * @return string
     */
    public static function notices(array $notices): string {
        global $OUTPUT;
        $html = '';
        foreach ($notices as $notice) {
            $html .= $OUTPUT->notification(s((string)$notice), 'info');
        }
        return $html;
    }

    /**
     * Format a timestamp in the learner's timezone.
     *
     * @param int $timestamp Unix timestamp or zero.
     * @return string Escaped date label.
     */
    private static function date(int $timestamp): string {
        return s($timestamp > 0 ? userdate($timestamp, get_string('strftimedate', 'langconfig')) :
            get_string('learner_nodate', 'local_trainingmatrix'));
    }

    /**
     * Render a destination only when the service supplied a safe Moodle URL.
     *
     * @param array $item Training item.
     * @param string $label Plain-text label.
     * @return string
     */
    private static function link(array $item, string $label): string {
        $url = $item['url'] ?? null;
        if ($url instanceof \moodle_url) {
            $scheme = parse_url($url->out(false), PHP_URL_SCHEME);
            if ($scheme === null || in_array(strtolower((string)$scheme), ['http', 'https'], true)) {
                return \html_writer::link($url, s($label));
            }
        }
        return s($label);
    }

    /**
     * Render the full learner table with separate due and expiry dates.
     *
     * @param array $items Already filtered and paginated items.
     * @return string
     */
    public static function table(array $items): string {
        $kinds = self::kinds();
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable table';
        $table->caption = s(get_string('learner_traininglist', 'local_trainingmatrix'));
        $table->head = array_map(static function (string $key): string {
            return s(get_string('learner_' . $key, 'local_trainingmatrix'));
        }, ['name', 'kind', 'source', 'status', 'due', 'expires', 'action']);
        foreach ($items as $item) {
            $table->data[] = [
                self::link($item, (string)$item['name']),
                s($kinds[$item['kind']] ?? (string)$item['kind']),
                s((string)$item['source']),
                s((string)$item['status']),
                self::date((int)$item['due']),
                self::date((int)$item['expires']),
                !empty($item['action']) ? self::link($item, (string)$item['action']) :
                    s(get_string('learner_noaction', 'local_trainingmatrix')),
            ];
        }
        return \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * Show at most five items, prioritising actions then the earliest due date.
     *
     * @param array $data Authenticated learner service result.
     * @return string
     */
    public static function block(array $data): string {
        $items = array_values($data['items']);
        // Use original order as the final tie-breaker for predictable summaries.
        $ordered = [];
        foreach ($items as $index => $item) {
            $ordered[] = ['item' => $item, 'index' => $index];
        }
        usort($ordered, static function (array $a, array $b): int {
            $first = $a['item'];
            $second = $b['item'];
            $action = (int)(trim((string)$second['action']) !== '') <=>
                (int)(trim((string)$first['action']) !== '');
            if ($action !== 0) {
                return $action;
            }
            $duea = (int)$first['due'] > 0 ? (int)$first['due'] : PHP_INT_MAX;
            $dueb = (int)$second['due'] > 0 ? (int)$second['due'] : PHP_INT_MAX;
            return ($duea <=> $dueb) ?: ($a['index'] <=> $b['index']);
        });

        $html = self::notices($data['notices']);
        $kinds = self::kinds();
        if (!$ordered) {
            $html .= \html_writer::tag('p', s(get_string('learner_empty', 'local_trainingmatrix')));
        } else {
            $html .= \html_writer::tag('p', s(get_string('learner_priorityintro', 'local_trainingmatrix')),
                ['class' => 'text-muted']);
            $html .= \html_writer::start_tag('ul', ['class' => 'list-unstyled']);
            foreach (array_slice($ordered, 0, 5) as $entry) {
                $item = $entry['item'];
                $html .= \html_writer::start_tag('li', ['class' => 'mb-3 pb-2 border-bottom']);
                $html .= \html_writer::div(
                    \html_writer::tag('strong', self::link($item, (string)$item['name'])));
                $html .= \html_writer::div(s($kinds[$item['kind']] ?? (string)$item['kind']) .
                    ' · ' . s((string)$item['source']), 'small text-muted');
                $html .= \html_writer::div(s((string)$item['status']), 'small');
                foreach (['due', 'expires'] as $datekey) {
                    if ((int)$item[$datekey] > 0) {
                        $html .= \html_writer::div(s(get_string('learner_' . $datekey, 'local_trainingmatrix')) .
                            ': ' . self::date((int)$item[$datekey]), 'small');
                    }
                }
                if (trim((string)$item['action']) !== '') {
                    $html .= \html_writer::div(self::link($item, (string)$item['action']), 'mt-1');
                }
                $html .= \html_writer::end_tag('li');
            }
            $html .= \html_writer::end_tag('ul');
        }
        $html .= \html_writer::link(new \moodle_url('/local/trainingmatrix/my.php'),
            s(get_string('learner_viewall', 'local_trainingmatrix')), ['class' => 'btn btn-secondary btn-block']);
        return $html;
    }
}
