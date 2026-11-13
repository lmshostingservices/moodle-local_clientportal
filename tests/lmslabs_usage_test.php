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

namespace local_clientportal;

#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ledgers::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sitereport::class)]
/**
 * Tests for LMS Labs credits reported by plugins, plugin ledgers and the site owner's Clients report.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\api
 * @covers     \local_clientportal\ledgers
 * @covers     \local_clientportal\sitereport
 */
final class lmslabs_usage_test extends \advanced_testcase {
    /**
     * A billed client with a learner.
     *
     * @return array
     */
    private function setup_data(): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $client = manager::save_client((object)['name' => 'Bayside', 'cohortid' => 0, 'active' => 1, 'billing' => 1,
            'ratecredit' => 0.10, 'ratelearner' => 0, 'rateenrol' => 0]);
        $zoe = $this->getDataGenerator()->create_user();
        cohort_add_member((int)manager::get_client($client)->cohortid, $zoe->id);
        return compact('client', 'zoe');
    }

    public function test_charged_credits_reads_the_reply(): void {
        $this->assertSame(7.0, api::charged_credits(['success' => true, 'creditsCharged' => 7], 3));
        $this->assertSame(4.0, api::charged_credits('{"ok":true,"data":{"credits_used":4}}', 3));
        $this->assertSame(2.0, api::charged_credits((object)['usage' => (object)['charged' => 2]], 3));
        // No figure in the reply: the plugin's price.
        $this->assertSame(3.0, api::charged_credits(['success' => true, 'credits' => 950], 3));
        // Failed calls are not charged.
        $this->assertNull(api::charged_credits(['success' => false, 'creditsCharged' => 7], 3));
        $this->assertNull(api::charged_credits(['ok' => false], 3));
        $this->assertNull(api::charged_credits(['error' => 'INSUFFICIENT_CREDITS'], 3));
        // A zero charge (for example a free repeat) stays zero.
        $this->assertSame(0.0, api::charged_credits(['creditsCharged' => 0], 5));
    }

    public function test_record_lmslabs_call_counts_a_job_once(): void {
        global $DB;
        $d = $this->setup_data();
        $system = \context_system::instance()->id;
        $reply = ['ok' => true, 'status' => 'done', 'creditsCharged' => 12];
        $this->assertGreaterThan(0, api::record_lmslabs_call($d['zoe']->id, $system, 'mod_x', $reply, 0, 'Job', 'job-1'));
        // Polled again: not counted twice.
        $this->assertSame(0, api::record_lmslabs_call($d['zoe']->id, $system, 'mod_x', $reply, 0, 'Job', 'job-1'));
        // The same job id from another plugin is a different charge.
        $this->assertGreaterThan(0, api::record_lmslabs_call($d['zoe']->id, $system, 'mod_y', $reply, 0, 'Job', 'job-1'));
        // Nothing recorded for a zero charge or a failure.
        $this->assertSame(0, api::record_lmslabs_call($d['zoe']->id, $system, 'mod_x', ['ok' => true], 0));
        $this->assertSame(0, api::record_lmslabs_call($d['zoe']->id, $system, 'mod_x', ['success' => false], 5));
        $this->assertEquals(24, $DB->get_field_sql('SELECT SUM(quantity) FROM {local_clientportal_usage}'));
    }

    public function test_course_is_used_when_the_plugin_cannot_see_it(): void {
        global $COURSE, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $client = manager::save_client((object)['name' => 'Coastline', 'cohortid' => 0, 'active' => 1,
            'features' => 'courses']);
        $categoryid = courses::ensure_category(manager::get_client($client))->id;
        $course = $this->getDataGenerator()->create_course(['category' => $categoryid]);
        // A trainer who is in no client's cohort: only the course says which client.
        $trainer = $this->getDataGenerator()->create_user();
        $COURSE = $course;
        $this->assertGreaterThan(0, api::record_lmslabs_call(
            $trainer->id,
            \context_system::instance()->id,
            'mod_aibranchedscenario',
            ['creditsUsed' => 9],
            0
        ));
        $this->assertEquals($client, $DB->get_field('local_clientportal_usage', 'clientid', []));
    }

    public function test_sitereport_totals(): void {
        $d = $this->setup_data();
        $system = \context_system::instance()->id;
        api::record_lmslabs_call($d['zoe']->id, $system, 'mod_contentcreator', ['creditsCharged' => 30], 0);
        api::record_usage($d['zoe']->id, $system, 'core_ai', 5, 'Moodle AI');
        $report = sitereport::build(time() - HOURSECS, time() + HOURSECS);
        $this->assertEquals(30, $report['totals']->labscredits);
        $this->assertEquals(5, $report['totals']->aicredits);
        $this->assertEquals(1, $report['totals']->learners);
        $this->assertEquals(3.5, round($report['totals']->amount, 2));
        $plugins = sitereport::by_plugin(time() - HOURSECS, time() + HOURSECS);
        $this->assertCount(2, $plugins);
        $weekly = sitereport::weekly(4);
        $this->assertCount(4, $weekly['labels']);
        $this->assertEquals(30, end($weekly['labs']));
    }
}
