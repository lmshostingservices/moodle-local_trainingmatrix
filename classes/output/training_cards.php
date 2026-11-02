<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace local_trainingmatrix\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Native, read-only graduation of the approved training block designs.
 * @package local_trainingmatrix
 * @copyright 2026 LMS-Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class training_cards {
    /** Shared assets; no framework runtime or external font requests. */
    private static function assets(): void {
        global $PAGE;
        $PAGE->requires->js('/local/trainingmatrix/cards.js');
    }

    /** Escaped text element. */
    private static function text(string $tag, string $text, string $class = ''): string {
        return \html_writer::tag($tag, s($text), ['class' => $class]);
    }

    /** Inline decorative icons from the approved design. */
    private static function icon(string $type): string {
        $paths = [
            'mark' => 'M12 3.2 19 6v5.1c0 4.7-3 7.9-7 9.7-4-1.8-7-5-7-9.7V6l7-2.8Z M8.7 12.2l2.1 2.1 4.5-4.6',
            'course' => 'M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15Z M4 18a2.5 2.5 0 0 1 2.5-2.5H20 M8 7h7 M8 10h5',
            'file' => 'M7 3.5h7l5 5v12H7a2 2 0 0 1-2-2v-13a2 2 0 0 1 2-2Z M14 3.5v5h5 M8.5 13h7 M8.5 16.5h5',
            'download' => 'M12 3v12m-4-4 4 4 4-4 M5 17v3h14v-3',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' .
            'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' .
            $paths[$type] . '"/></svg>';
    }

    /** Section header. */
    private static function header(bool $teacher): string {
        return '<header class="tmx-head"><div>' .
            self::text('div', $teacher ? get_string('cards_kicker_teacher', 'local_trainingmatrix') : get_string('cards_kicker_learner', 'local_trainingmatrix'), 'tmx-kicker') .
            self::text('h2', $teacher ? get_string('cards_title_teacher', 'local_trainingmatrix') : get_string('cards_title_learner', 'local_trainingmatrix'), 'tmx-title') .
            self::text('p', $teacher ? get_string('cards_subtitle_teacher', 'local_trainingmatrix') :
                get_string('cards_subtitle_learner', 'local_trainingmatrix'), 'tmx-subtitle') .
            '</div><div class="tmx-head-mark">' . self::icon('mark') . '</div></header>';
    }

    /** Progressive enhancement: every row remains available without JavaScript. */
    private static function controls(bool $teacher): string {
        $options = ['all' => $teacher ? get_string('cards_filter_allstaff', 'local_trainingmatrix') : get_string('cards_filter_all', 'local_trainingmatrix')];
        foreach (['course', 'certificate', 'locked', 'awaiting', 'completed', 'expired', 'expiring', 'revoked'] as $key) {
            $options[$key] = get_string('cards_filter_' . $key, 'local_trainingmatrix');
        }
        $html = '<div class="tmx-toolbar" hidden><label class="tmx-search">' .
            self::text('span', $teacher ? get_string('cards_search_teacher', 'local_trainingmatrix') : get_string('cards_search_learner', 'local_trainingmatrix')) .
            '<input type="search" data-tmx-search></label><label>' . s(get_string('cards_filter', 'local_trainingmatrix')) .
            '<select data-tmx-filter class="tmx-filter">';
        foreach ($options as $value => $label) {
            $html .= \html_writer::tag('option', s($label), ['value' => $value]);
        }
        $countlabel = $teacher ? get_string('cards_count_staff', 'local_trainingmatrix') : get_string('cards_count_items', 'local_trainingmatrix');
        return $html . '</select></label><button type="button" class="tmx-secondary" data-tmx-density ' .
            'aria-pressed="false">' . s(get_string('cards_compact', 'local_trainingmatrix')) . '</button></div>' .
            \html_writer::tag('p', '', ['class' => 'tmx-meta', 'data-tmx-count' => '', 'aria-live' => 'polite',
                'data-tmx-label' => $countlabel, 'data-tmx-nomatch' => get_string('cards_nomatches', 'local_trainingmatrix')]);
    }

    /** Dates always retain their individual source meaning. */
    private static function dates(array $dates): string {
        $html = '';
        foreach ($dates as $label => $date) {
            if ($date > 0) {
                $html .= self::text('p', $label . ': ' . userdate($date), 'tmx-date');
            }
        }
        return $html;
    }

    /** Machine states used only for filtering; never infer compliance. */
    private static function states(array $item): array {
        $states = [$item['kind'], $item['state'] ?? 'neutral'];
        foreach ($item['evidence'] ?? [] as $evidence) {
            if ($evidence['awaiting']) {
                $states[] = 'awaiting';
            }
        }
        if ($item['kind'] === 'certificate' && $item['expires'] > time() &&
                $item['expires'] <= time() + 30 * DAYSECS && ($item['state'] ?? '') === 'valid') {
            $states[] = 'expiring';
        }
        return array_unique($states);
    }

    /** Only service-authorised stored PDF URLs can reach this control. */
    private static function download(array $item): string {
        if ($item['kind'] !== 'certificate' || $item['source'] !== 'Certificate Pro' ||
                !($item['url'] instanceof \moodle_url)) {
            return '';
        }
        $label = get_string('cards_download', 'local_trainingmatrix', $item['name']);
        return '<span class="tmx-tooltip-wrap">' .
            \html_writer::link($item['url'], self::icon('download'), [
                'class' => 'tmx-download', 'aria-label' => $label, 'title' => $label]) .
            self::text('span', $label, 'tmx-tooltip') . '</span>';
    }

    /** All items, including every enrolled course, without a five-row truncation. */
    public static function items(array $items): string {
        $html = '<div class="tmx-list">';
        foreach ($items as $item) {
            $state = $item['state'] ?? 'neutral';
            $tokens = implode(' ', self::states($item));
            $html .= \html_writer::start_tag('article', ['class' => 'tmx-card', 'data-tmx-row' => 'item',
                'data-tmx-states' => $tokens]);
            $html .= '<div class="tmx-card-main"><span class="tmx-card-icon">' .
                self::icon($item['kind'] === 'certificate' ? 'file' : 'course') .
                '</span><div class="tmx-card-body">' . self::text('h3', $item['name'], 'tmx-card-title') .
                self::text('p', (learner_view::kinds()[$item['kind']] ?? $item['kind']) . ' · ' .
                    $item['source'], 'tmx-card-caption') . '</div>' .
                '<div class="tmx-card-right">' .
                self::text('span', $item['status'], 'tmx-status ' . $state) . '</div><div class="tmx-card-actions">';
            if ($item['kind'] !== 'certificate' && $item['url'] instanceof \moodle_url) {
                $html .= \html_writer::link($item['url'], get_string('cards_open', 'local_trainingmatrix'), ['class' => 'tmx-primary tmx-course-action']);
            }
            $html .= self::download($item);
            if ($item['kind'] === 'course') {
                foreach ($items as $certificate) {
                    if ($certificate['kind'] === 'certificate' &&
                            !empty($certificate['courseid']) && $certificate['courseid'] === $item['courseid']) {
                        $html .= self::download($certificate);
                    }
                }
            }
            $html .= '</div></div><details class="tmx-details"><summary>' .
                s(get_string('cards_details', 'local_trainingmatrix', $item['name'])) . '</summary>' .
                self::text('p', $item['status']) . self::text('p', $item['action']) .
                self::dates([get_string('cards_due', 'local_trainingmatrix') => $item['due'], get_string('cards_expires', 'local_trainingmatrix') => $item['expires']] +
                    ($item['dates'] ?? []));
            foreach ($item['evidence'] ?? [] as $evidence) {
                $html .= '<div class="tmx-detail-note">' . self::text('strong', $evidence['name']) .
                    self::text('p', $evidence['status']) . self::dates($evidence['dates']) . '</div>';
            }
            if ($item['source'] === 'RTO Compliance' && $item['url'] instanceof \moodle_url) {
                $html .= \html_writer::link($item['url'], get_string('cards_wallet', 'local_trainingmatrix'), ['class' => 'tmx-secondary']);
            }
            $html .= '</details></article>';
        }
        return $html . (!$items ? self::text('p', get_string('cards_empty', 'local_trainingmatrix'), 'tmx-empty') : '') . '</div>';
    }

    /** Learner dashboard. */
    public static function learner(array $data): string {
        self::assets();
        return '<section class="tmx" data-tmx-root>' . self::header(false) . self::controls(false) .
            self::items($data['items']) . self::explanation() . self::notices($data['notices']) .
            \html_writer::link(new \moodle_url('/local/trainingmatrix/my.php'), get_string('cards_viewall', 'local_trainingmatrix'),
                ['class' => 'tmx-primary']) . '</section>';
    }

    private static function explanation(): string {
        return self::text('p', get_string('cards_explanation', 'local_trainingmatrix'), 'tmx-callout');
    }

    private static function notices(array $notices): string {
        return '<details class="tmx-permission"><summary>' . s(get_string('cards_sources', 'local_trainingmatrix')) . '</summary>' .
            learner_view::notices($notices) . '</details>';
    }

    /** Maximum number of people rendered in the overview; the rest are reached through the dashboard. */
    public const TEACHER_LIMIT = 25;

    /** Authorised active-staff overview with explicit subject projection. */
    public static function teacher(): string {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
        if (!has_capability('local/trainingmatrix:viewall', \context_system::instance())) {
            return '';
        }
        self::assets();
        $where = "u.deleted = 0 AND u.suspended = 0 AND EXISTS
                (SELECT 1 FROM {local_tm_staff} s WHERE s.userid = u.id AND s.status = :status)";
        $params = ['status' => 'active'];
        $total = $DB->count_records_sql("SELECT COUNT(1) FROM {user} u WHERE $where", $params);
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $staff = $DB->get_records_sql("SELECT u.id, {$fields} FROM {user} u WHERE $where
            ORDER BY u.lastname, u.firstname, u.id", $params, 0, self::TEACHER_LIMIT);
        $counts = ['staff' => $total, 'awaiting' => 0, 'expiring' => 0, 'expiredrevoked' => 0];
        // Preserve the established Matrix ledger as a separate source, never as an
        // inference from the absence of expired Certificate Pro documents.
        $matrix = local_trainingmatrix_get_compliance_stats();
        $rows = '';
        foreach ($staff as $person) {
            $data = \local_trainingmatrix\learner_training::for_user((int)$person->id);
            $states = [];
            foreach ($data['items'] as $item) {
                $states = array_merge($states, self::states($item));
            }
            $counts['awaiting'] += (int)in_array('awaiting', $states, true);
            $counts['expiring'] += (int)in_array('expiring', $states, true);
            $counts['expiredrevoked'] += (int)(in_array('expired', $states, true) ||
                in_array('revoked', $states, true));
            $rows .= \html_writer::start_tag('details', ['class' => 'tmx-staff', 'data-tmx-row' => 'staff',
                'data-tmx-states' => implode(' ', array_unique($states))]);
            $rows .= '<summary class="tmx-staff-summary"><span class="tmx-avatar">' .
                s(\core_text::substr($person->firstname, 0, 1) . \core_text::substr($person->lastname, 0, 1)) .
                '</span><span class="tmx-staff-info">' . self::text('strong', fullname($person), 'tmx-staff-name') .
                self::text('span', get_string('cards_records', 'local_trainingmatrix', count($data['items'])),
                    'tmx-staff-meta') .
                '</span><span class="tmx-evidence-label">' . s(get_string('cards_viewevidence', 'local_trainingmatrix')) .
                '</span></summary><div class="tmx-staff-detail">' .
                self::items($data['items']) . self::notices($data['notices']) . '</div></details>';
        }
        $html = '<section class="tmx tmx-teacher" data-tmx-root>' . self::header(true) . '<div class="tmx-stats">';
        foreach ($counts as $key => $count) {
            $html .= '<div class="tmx-stat">' . self::text('div', get_string('cards_stat_' . $key, 'local_trainingmatrix'),
                'tmx-stat-top') . self::text('div', (string)$count, 'tmx-stat-value') .
                self::text('div', get_string('cards_statcaption', 'local_trainingmatrix'), 'tmx-stat-caption') . '</div>';
        }
        $more = '';
        if ($total > count($staff)) {
            $more = self::text('p', get_string('cards_moreinview', 'local_trainingmatrix',
                ['shown' => count($staff), 'total' => $total]), 'tmx-meta');
        }
        return $html . '</div>' . self::text('p', get_string('cards_ledger', 'local_trainingmatrix', (object)$matrix),
            'tmx-callout') . self::explanation() . self::controls(true) . '<div class="tmx-staff-list">' .
            ($rows ?: self::text('p', get_string('cards_nostaff', 'local_trainingmatrix'), 'tmx-empty')) . '</div>' . $more .
            '<div class="tmx-footer-actions">' .
            \html_writer::link(new \moodle_url('/local/trainingmatrix/index.php'),
                get_string('cards_opendashboard', 'local_trainingmatrix'), ['class' => 'tmx-primary']) .
            \html_writer::link(new \moodle_url('/local/trainingmatrix/index.php', ['tab' => 'reports']),
                get_string('cards_viewreports', 'local_trainingmatrix'), ['class' => 'tmx-secondary']) . '</div></section>';
    }
}
