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

#[\PHPUnit\Framework\Attributes\CoversClass(usage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(xero::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(api::class)]
/**
 * Tests for usage and Xero billing.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_clientportal\usage
 * @covers     \local_clientportal\xero
 * @covers     \local_clientportal\api
 */
final class billing_test extends \advanced_testcase {
    /**
     * A billed client with a learner.
     *
     * @return array
     */
    private function setup_data(): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->libdir . '/filelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $client = manager::save_client((object)['name' => 'BHP', 'cohortid' => 0, 'active' => 1, 'billing' => 1,
            'ratecredit' => 0.05, 'ratelearner' => 2, 'rateenrol' => 10]);
        $other = manager::save_client((object)['name' => 'Rio', 'cohortid' => 0, 'active' => 1]);
        $zoe = $this->getDataGenerator()->create_user();
        cohort_add_member((int)manager::get_client($client)->cohortid, $zoe->id);
        return compact('client', 'other', 'zoe');
    }

    public function test_usage_is_filed_under_the_right_client(): void {
        $d = $this->setup_data();
        $system = \context_system::instance()->id;
        $this->assertGreaterThan(0, api::record_usage($d['zoe']->id, $system, 'mod_contentcreator', 5, 'Voiceover'));
        $stranger = $this->getDataGenerator()->create_user();
        $this->assertSame(0, api::record_usage($stranger->id, $system, 'mod_contentcreator', 5));
        $summary = usage::summary(time() - HOURSECS, time() + HOURSECS);
        $this->assertEquals(5, $summary[$d['client']]->credits);
        $this->assertEquals(0, $summary[$d['other']]->credits);
        $this->assertCount(1, $summary[$d['client']]->lines);
        $this->assertEquals(0.25, $summary[$d['client']]->total);
    }

    public function test_core_ai_actions_are_collected_once(): void {
        global $DB;
        $d = $this->setup_data();
        if (!$DB->get_manager()->table_exists('ai_action_register')) {
            $this->markTestSkipped('No Moodle AI on this version.');
        }
        set_config('lastaiaction', 0, 'local_clientportal');
        foreach (['generate_text', 'generate_image'] as $action) {
            $DB->insert_record('ai_action_register', (object)['actionname' => $action, 'actionid' => 0, 'success' => 1,
                'userid' => $d['zoe']->id, 'contextid' => \context_system::instance()->id, 'provider' => 'x',
                'timecreated' => time(), 'timecompleted' => time()]);
        }
        $this->assertSame(2, usage::collect_core_ai());
        $this->assertSame(0, usage::collect_core_ai());
        $this->assertEquals(6, $DB->get_field_sql("SELECT SUM(quantity) FROM {local_clientportal_usage}"));
    }

    public function test_xero_adds_weekly_lines_to_one_monthly_draft(): void {
        global $DB;
        $d = $this->setup_data();
        set_config('xeroclientid', 'abc', 'local_clientportal');
        set_config('xeroclientsecret', 'def', 'local_clientportal');
        set_config('xerotenantid', 'tenant-1', 'local_clientportal');
        set_config('xerotokens', \core\encryption::encrypt(json_encode(['access_token' => 'a', 'refresh_token' => 'r',
            'expires' => time() + 600])), 'local_clientportal');
        $xero = new xero();
        $this->assertTrue($xero->connected());

        // Week 1: contact lookup (none), contact created, invoice created.
        $monday = usage::week_start(make_timestamp(2026, 11, 18, 12));
        $DB->insert_record('local_clientportal_usage', (object)['clientid' => $d['client'], 'userid' => $d['zoe']->id,
            'contextid' => 1, 'component' => 'x', 'kind' => 'aicredits', 'quantity' => 100, 'description' => '',
            'source' => 'api', 'sourceid' => 0, 'timecreated' => $monday - 3 * DAYSECS]);
        // Mocked responses are served last in, first out.
        \curl::mock_response(json_encode(['Invoices' => [['InvoiceID' => 'inv-1', 'InvoiceNumber' => 'INV-0042']]]));
        \curl::mock_response(json_encode(['Contacts' => [['ContactID' => 'c-1']]]));
        \curl::mock_response(json_encode(['Contacts' => []]));
        $client = manager::get_client($d['client']);
        $result = $xero->bill_client($client, $monday - 7 * DAYSECS, $monday);
        $this->assertStringContainsString('INV-0042', $result);
        $invoice = $DB->get_record('local_clientportal_invoice', ['clientid' => $d['client']]);
        $this->assertSame('inv-1', $invoice->xeroinvoiceid);
        $this->assertEquals(5.0, (float)$invoice->total);
        $this->assertSame('c-1', $DB->get_field('local_clientportal_client', 'xerocontact', ['id' => $d['client']]));

        // The same week again: nothing sent twice.
        $this->assertStringContainsString(
            get_string('xero_alreadybilled', 'local_clientportal'),
            $xero->bill_client(manager::get_client($d['client']), $monday - 7 * DAYSECS, $monday)
        );

        // Week 2 in the same month: lines added to the same draft.
        $DB->insert_record('local_clientportal_usage', (object)['clientid' => $d['client'], 'userid' => $d['zoe']->id,
            'contextid' => 1, 'component' => 'x', 'kind' => 'aicredits', 'quantity' => 20, 'description' => '',
            'source' => 'api', 'sourceid' => 0, 'timecreated' => $monday + 2 * DAYSECS]);
        \curl::mock_response(json_encode(['Invoices' => [['InvoiceID' => 'inv-1', 'InvoiceNumber' => 'INV-0042']]]));
        $xero->bill_client(manager::get_client($d['client']), $monday, $monday + 7 * DAYSECS);
        $invoice = $DB->get_record('local_clientportal_invoice', ['clientid' => $d['client']]);
        $this->assertCount(2, json_decode($invoice->lines, true));
        $this->assertEquals(6.0, (float)$invoice->total);
        $this->assertSame(1, $DB->count_records('local_clientportal_invoice', ['clientid' => $d['client']]));
    }

    public function test_xero_error_is_kept_and_retried(): void {
        global $DB;
        $d = $this->setup_data();
        set_config('xeroclientid', 'abc', 'local_clientportal');
        set_config('xeroclientsecret', 'def', 'local_clientportal');
        set_config('xerotenantid', 'tenant-1', 'local_clientportal');
        set_config('xerotokens', \core\encryption::encrypt(json_encode(['access_token' => 'a', 'refresh_token' => 'r',
            'expires' => time() + 600])), 'local_clientportal');
        $DB->set_field('local_clientportal_client', 'xerocontact', 'c-1', ['id' => $d['client']]);
        $monday = usage::week_start(time());
        $DB->insert_record('local_clientportal_usage', (object)['clientid' => $d['client'], 'userid' => 0, 'contextid' => 1,
            'component' => 'x', 'kind' => 'aicredits', 'quantity' => 10, 'description' => '', 'source' => 'api',
            'sourceid' => 0, 'timecreated' => $monday - DAYSECS]);
        \curl::mock_response(json_encode(['Message' => 'A validation exception occurred']));
        $xero = new xero();
        $result = $xero->bill_client(manager::get_client($d['client']), $monday - 7 * DAYSECS, $monday);
        $this->assertStringContainsString('Not sent', $result);
        $this->assertFalse((bool)get_config('local_clientportal', 'billedto_' . $d['client']));
    }
}
