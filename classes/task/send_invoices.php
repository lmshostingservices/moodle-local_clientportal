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

namespace local_clientportal\task;

/**
 * Once a week, add each billed client's usage to their draft invoice in Xero.
 *
 * @package    local_clientportal
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_invoices extends \core\task\scheduled_task {
    /**
     * Name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sendinvoices', 'local_clientportal');
    }

    /**
     * Run.
     */
    public function execute() {
        \local_clientportal\usage::collect_core_ai();
        \local_clientportal\ledgers::collect();
        $xero = new \local_clientportal\xero();
        if (!$xero->connected()) {
            mtrace('Client portal: Xero is not connected; nothing sent.');
            return;
        }
        foreach ($xero->bill_week() as $line) {
            mtrace('Client portal: ' . $line);
        }
    }
}
