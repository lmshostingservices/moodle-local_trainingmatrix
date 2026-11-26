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

namespace local_trainingmatrix;

use local_trainingmatrix\local\byok;

/**
 * Tests for generating competencies on the site's own AI.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(byok::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(local\ai_client::class)]
final class byok_test extends \advanced_testcase {
    /** @var string A Markdown code fence (three backticks). */
    private const FENCE = "\x60\x60\x60";

    /** @var array Requests the mocked provider received. */
    protected $requests = [];

    /**
     * Licence the site for Bring your own AI on a test key, with text on an Anthropic key.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\local_aiconfig\byok\ai')) {
            $this->markTestSkipped('AI Grader Central Config 1.1.0 or later is not installed.');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
        $pair = sodium_crypto_sign_keypair();
        set_config('testpublickey', base64_encode(sodium_crypto_sign_publickey($pair)), 'local_aiconfig');
        $payload = json_encode(['v' => 1, 'id' => 'lic_t', 'site' => \local_aiconfig\byok\licence::site_host(),
            'plan' => 'pro', 'byok' => true, 'products' => ['*'], 'sub' => 'sub_t', 'exp' => time() + DAYSECS]);
        $b64 = function (string $s): string {
            return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        };
        set_config('licencekey', $b64($payload) . '.' .
            $b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))), 'local_aiconfig');
        set_config('src_text', 'ownkey', 'local_aiconfig');
        set_config('vendor_text', 'anthropic', 'local_aiconfig');
        set_config('key_anthropic', 'sk-test', 'local_aiconfig');
    }

    /**
     * Clean up the provider mock.
     */
    protected function tearDown(): void {
        unset($GLOBALS['local_aiconfig_byok_mock']);
        parent::tearDown();
    }

    /**
     * Make the provider answer with this text, or fail when null.
     *
     * @param string|null $answer
     */
    protected function answer(?string $answer): void {
        $this->requests = [];
        $GLOBALS['local_aiconfig_byok_mock'] = function ($url, $headers, $body) use ($answer) {
            $this->requests[] = $body;
            if ($answer === null) {
                throw new \moodle_exception('byok_providererror', 'local_aiconfig', '', 'Overloaded');
            }
            return ['content' => [['type' => 'text', 'text' => $answer]]];
        };
    }

    public function test_generates_without_lms_labs_credentials(): void {
        global $DB;
        $pos = $DB->insert_record('local_trainingmatrix_pos', (object)['name' => 'Haul truck operator',
            'description' => '<p>Operates haul trucks on the Pilbara run.</p>']);
        $this->answer(self::FENCE . "json\n" . json_encode([
            ['name' => 'Pre-start checks', 'code' => 'PS1', 'category' => 'Safety', 'description' => 'Daily checks',
                'mandatory' => true],
            ['name' => 'Fatigue management', 'code' => 'FM1', 'category' => 'Compliance', 'description' => 'Manage fatigue',
                'mandatory' => false],
        ]) . "\n" . self::FENCE);
        $client = new local\ai_client();
        $this->assertTrue($client->is_configured());
        $this->assertSame(-1, $client->get_credits()['credits']);
        $result = $client->generate((int)$pos);
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame(['Pre-start checks', 'Fatigue management'], $result['created']);
        $this->assertSame(0, $result['credits_used']);
        $this->assertSame(2, $DB->count_records('local_trainingmatrix_poscomp', ['positionid' => $pos]));
        $this->assertStringContainsString('expert HR consultant', $this->requests[0]['system']);
        $user = $this->requests[0]['messages'][0]['content'][0]['text'];
        $this->assertStringContainsString('position: "Haul truck operator"', $user);
        $this->assertStringContainsString('Position description: Operates haul trucks on the Pilbara run.', $user);
        $this->assertSame(2000, $this->requests[0]['max_tokens']);
        $this->assertSame(0.7, $this->requests[0]['temperature']);
    }

    public function test_invalid_answers_are_refused(): void {
        $item = ['name' => 'A', 'code' => 'A1', 'category' => 'Safety', 'description' => 'D', 'mandatory' => true];
        $this->assertTrue(byok::valid([$item]));
        $this->assertFalse(byok::valid([]));
        $this->assertFalse(byok::valid([$item, ['code' => 'a1', 'name' => 'B'] + $item]));
        $this->assertFalse(byok::valid([$item, ['name' => 'a'] + ['code' => 'B1'] + $item]));
        $this->assertFalse(byok::valid([['category' => 'Other'] + $item]));
        $this->assertFalse(byok::valid([['name' => 'Safe <b>work</b>'] + $item]));
        $this->assertFalse(byok::valid([['mandatory' => 'yes'] + $item]));
        $this->assertFalse(byok::valid(['x' => $item]));
        $this->answer('{"competencies":[]}');
        $result = byok::generate('Generate competencies');
        $this->assertFalse($result['ok']);
        $this->assertSame('INVALID_PROVIDER_RESULT', $result['error']);
    }

    public function test_provider_failure_is_reported(): void {
        global $DB;
        $pos = $DB->insert_record('local_trainingmatrix_pos', (object)['name' => 'Operator']);
        $this->answer(null);
        $result = (new local\ai_client())->generate((int)$pos);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Your AI provider could not generate competencies', $result['error']);
        $this->assertSame(0, $DB->count_records('local_trainingmatrix_comp'));
    }
}
