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

declare(strict_types=1);

namespace webservice_elediamcp\task;

use core\task\scheduled_task;
use webservice_elediamcp\local\token_manager;

/**
 * Scheduled task that prunes the audit records of long-revoked MCP tokens.
 *
 * Connector-provisioned tokens are minted and revoked frequently, so without
 * pruning their revoked metadata rows grow unbounded. The retention window is
 * configurable (token_retention_days); 0 keeps every record indefinitely.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_revoked_tokens extends scheduled_task {
    /**
     * Return the task name shown in the scheduled tasks UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_prune_revoked_tokens', 'webservice_elediamcp');
    }

    /**
     * Delete revoked token records older than the configured retention window.
     *
     * @return void
     */
    public function execute(): void {
        $configured = get_config('webservice_elediamcp', 'token_retention_days');
        $days = ($configured === false || $configured === '') ? 30 : (int) $configured;

        if ($days <= 0) {
            mtrace('webservice_elediamcp: revoked-token pruning is disabled (retention = 0).');
            return;
        }

        $deleted = token_manager::prune_revoked_tokens($days);
        mtrace("webservice_elediamcp: pruned {$deleted} revoked token record(s) "
            . "older than {$days} day(s).");
    }
}
