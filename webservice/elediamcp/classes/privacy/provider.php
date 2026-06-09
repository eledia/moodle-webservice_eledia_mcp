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

namespace webservice_elediamcp\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the MCP web service plugin.
 *
 * The protocol layer itself stores no personal data, but the token management
 * feature persists MCP token metadata (owner, service, label, timestamps and
 * revocation state) in {webservice_elediamcp_token}. That metadata is declared and
 * exported/erased here. The token secret is never stored.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('webservice_elediamcp_token', [
            'userid' => 'privacy:metadata:webservice_elediamcp_token:userid',
            'externalserviceid' => 'privacy:metadata:webservice_elediamcp_token:externalserviceid',
            'name' => 'privacy:metadata:webservice_elediamcp_token:name',
            'creatorid' => 'privacy:metadata:webservice_elediamcp_token:creatorid',
            'component' => 'privacy:metadata:webservice_elediamcp_token:component',
            'validuntil' => 'privacy:metadata:webservice_elediamcp_token:validuntil',
            'timecreated' => 'privacy:metadata:webservice_elediamcp_token:timecreated',
            'lastaccess' => 'privacy:metadata:webservice_elediamcp_token:lastaccess',
            'revoked' => 'privacy:metadata:webservice_elediamcp_token:revoked',
            'revokedby' => 'privacy:metadata:webservice_elediamcp_token:revokedby',
        ], 'privacy:metadata:webservice_elediamcp_token');

        return $collection;
    }

    /**
     * Return the contexts containing personal data for the given user.
     *
     * MCP token metadata lives at the system context.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT id FROM {webservice_elediamcp_token}
                 WHERE userid = :userid OR creatorid = :creatorid OR revokedby = :revokedby";
        $params = ['userid' => $userid, 'creatorid' => $userid, 'revokedby' => $userid];
        if (self::record_exists_sql($sql, $params)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Return all users with personal data in the given context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {webservice_elediamcp_token}', []);
        $userlist->add_from_sql('creatorid', 'SELECT creatorid FROM {webservice_elediamcp_token}', []);
        $userlist->add_from_sql('revokedby',
            'SELECT revokedby FROM {webservice_elediamcp_token} WHERE revokedby IS NOT NULL', []);
    }

    /**
     * Export all personal data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!in_array(CONTEXT_SYSTEM, array_map(static fn($c) => $c->contextlevel, $contextlist->get_contexts()), true)) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $records = $DB->get_records_select(
            'webservice_elediamcp_token',
            'userid = :userid OR creatorid = :creatorid OR revokedby = :revokedby',
            ['userid' => $userid, 'creatorid' => $userid, 'revokedby' => $userid],
            'timecreated ASC'
        );
        if (empty($records)) {
            return;
        }

        $data = [];
        foreach ($records as $record) {
            $data[] = (object) [
                'name' => $record->name,
                'externalserviceid' => $record->externalserviceid,
                'component' => $record->component,
                'owner' => (int) $record->userid === (int) $userid,
                'validuntil' => $record->validuntil ? transform::datetime($record->validuntil) : null,
                'timecreated' => transform::datetime($record->timecreated),
                'lastaccess' => $record->lastaccess ? transform::datetime($record->lastaccess) : null,
                'revoked' => transform::yesno($record->revoked),
                'timerevoked' => $record->timerevoked ? transform::datetime($record->timerevoked) : null,
            ];
        }

        writer::with_context(context_system::instance())->export_data(
            [get_string('tokens_heading', 'webservice_elediamcp')],
            (object) ['tokens' => $data]
        );
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if (!$context instanceof context_system) {
            return;
        }
        $DB->delete_records('webservice_elediamcp_token');
    }

    /**
     * Delete all data for the given user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (!in_array(CONTEXT_SYSTEM, array_map(static fn($c) => $c->contextlevel, $contextlist->get_contexts()), true)) {
            return;
        }
        self::erase_user($DB, (int) $contextlist->get_user()->id);
    }

    /**
     * Delete data for multiple users in the given context.
     *
     * @param approved_userlist $userlist The approved users and context.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::erase_user($DB, (int) $userid);
        }
    }

    /**
     * Erase a user's footprint: delete tokens they own, and detach them from
     * tokens they merely created or revoked for someone else.
     *
     * @param \moodle_database $db The database.
     * @param int $userid The user id.
     * @return void
     */
    protected static function erase_user(\moodle_database $db, int $userid): void {
        $db->delete_records('webservice_elediamcp_token', ['userid' => $userid]);
        $db->set_field('webservice_elediamcp_token', 'creatorid', 0, ['creatorid' => $userid]);
        $db->set_field_select('webservice_elediamcp_token', 'revokedby', null, 'revokedby = :userid',
            ['userid' => $userid]);
    }

    /**
     * Lightweight record_exists wrapper kept private to avoid leaking the SQL.
     *
     * @param string $sql The SQL.
     * @param array $params The parameters.
     * @return bool
     */
    protected static function record_exists_sql(string $sql, array $params): bool {
        global $DB;
        return $DB->record_exists_sql($sql, $params);
    }
}
