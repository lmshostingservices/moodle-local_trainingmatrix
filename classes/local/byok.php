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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_trainingmatrix\local;

/**
 * Bring your own AI: generate position competencies on this site's own AI instead of LMS Labs credits.
 *
 * A port of the LMS Labs /api/training-matrix/generate service: the same system prompt, token budget,
 * temperature and checks on the AI's answer, run through the shared engine in AI Grader Central Config
 * (local_aiconfig 1.1.0+). The prompt goes from this site straight to the site's AI provider; nothing is
 * sent to LMS Labs and no credits are spent.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class byok {
    /** This plugin, as named in the licence's products list. */
    const COMPONENT = 'local_trainingmatrix';

    /** The service's system prompt. */
    const SYSTEM = 'You are an expert HR consultant specializing in workplace competency frameworks. '
        . 'Always respond with valid JSON arrays.';

    /** Categories the service accepts. */
    const CATEGORIES = ['Safety', 'Technical', 'Leadership', 'Compliance', 'Professional Development', 'Induction'];

    /** @var callable|null Test hook: replaces the AI call with fn(system, user, maxtokens, temperature): string. */
    public static $aicall = null;

    /**
     * Should this site generate competencies on its own AI?
     *
     * @return bool
     */
    public static function enabled(): bool {
        return class_exists('\local_aiconfig\byok\ai')
            && \local_aiconfig\byok\ai::uses_own_ai(self::COMPONENT, 'text');
    }

    /**
     * Port of POST /api/training-matrix/generate.
     *
     * @param string $prompt The plugin's prompt.
     * @return array competencies on success; otherwise ok false with a teacher-readable message.
     */
    public static function generate(string $prompt): array {
        \core_php_time_limit::raise(300);
        $prompt = trim($prompt);
        if ($prompt === '' || \core_text::strlen($prompt) > 24000) {
            return ['ok' => false, 'error' => 'INVALID_INPUT', 'message' => get_string('ai_generationfailed', 'local_training' .
                'matrix')];
        }
        try {
            $text = self::ai(self::SYSTEM, $prompt, 2000, 0.7);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'PROVIDER_FAILED', 'message' => self::message($e)];
        }
        $text = preg_replace('/^\x60{3}(?:json)?\s*\n([\s\S]*?)\n\x60{3}$/i', '$1', trim($text));
        $output = json_decode($text, true);
        if (!self::valid($output) || strlen(json_encode($output)) > 65536) {
            return ['ok' => false, 'error' => 'INVALID_PROVIDER_RESULT',
                'message' => get_string('byok_failed', 'local_trainingmatrix', get_string(
                    'byok_invalid',
                    'local_trainingmatrix'
                ))];
        }
        $competencies = array_map(function ($c) {
            return ['name' => trim($c['name']), 'code' => trim($c['code']), 'category' => $c['category'],
                'description' => trim($c['description']), 'mandatory' => $c['mandatory']];
        }, $output);
        return ['ok' => true, 'competencies' => $competencies, 'credits_used' => 0, 'credits_remaining' => -1, 'byok' => true];
    }

    /**
     * The service's trainingMatrixOutput schema.
     *
     * @param mixed $items Decoded answer.
     * @return bool
     */
    public static function valid($items): bool {
        if (
            !is_array($items) || ($items !== [] && array_keys($items) !== range(0, count($items) - 1))
                || count($items) < 1 || count($items) > 20
        ) {
            return false;
        }
        $text = function ($v, int $max): bool {
            if (!is_string($v)) {
                return false;
            }
            $v = trim($v);
            return $v !== '' && \core_text::strlen($v) <= $max && !preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/', $v);
        };
        $codes = [];
        $names = [];
        foreach ($items as $c) {
            if (
                !is_array($c) || !$text($c['name'] ?? null, 255) || !$text($c['code'] ?? null, 100)
                    || !$text($c['description'] ?? null, 4000) || !is_bool($c['mandatory'] ?? null)
                    || !in_array($c['category'] ?? null, self::CATEGORIES, true)
            ) {
                return false;
            }
            $codes[\core_text::strtolower(trim($c['code']))] = true;
            $names[\core_text::strtolower(trim($c['name']))] = true;
        }
        return count($codes) === count($items) && count($names) === count($items);
    }

    /**
     * Call the site's own AI.
     *
     * @param string $system System prompt.
     * @param string $user User prompt.
     * @param int $maxtokens Output budget.
     * @param float $temperature Temperature.
     * @return string
     */
    private static function ai(string $system, string $user, int $maxtokens, float $temperature): string {
        if (self::$aicall) {
            return (string) call_user_func(self::$aicall, $system, $user, $maxtokens, $temperature);
        }
        return \local_aiconfig\byok\ai::text(self::COMPONENT, $system, $user, [
            'json' => true, 'maxtokens' => $maxtokens, 'temperature' => $temperature,
        ]);
    }

    /**
     * A readable message for a failed AI call.
     *
     * @param \Throwable $e The failure.
     * @return string
     */
    private static function message(\Throwable $e): string {
        if ($e instanceof \moodle_exception && $e->errorcode === 'byok_notavailable') {
            return get_string('byok_notavailable', 'local_trainingmatrix');
        }
        return get_string('byok_failed', 'local_trainingmatrix', s($e->getMessage()));
    }
}
