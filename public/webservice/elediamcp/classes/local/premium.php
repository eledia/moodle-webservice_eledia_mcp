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

namespace webservice_elediamcp\local;

/**
 * Integration with the optional eLeDia.ai Tutor Premium add-on.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class premium {
    /** @var string Feature id for unlocking the full MCP tool catalogue. */
    public const FEATURE_MCP_TOOLS = 'mcp_tools';

    /**
     * Whether the full MCP tool catalogue is unlocked.
     *
     * @return bool
     */
    public static function has_mcp_tools(): bool {
        return self::has_feature(self::FEATURE_MCP_TOOLS);
    }

    /**
     * Whether the optional premium add-on exposes a feature.
     *
     * @param string $feature Feature id.
     * @return bool
     */
    public static function has_feature(string $feature): bool {
        $class = '\\local_elediaai_tutor_premium\\feature';

        return class_exists($class)
            && method_exists($class, 'has_feature')
            && $class::has_feature($feature);
    }
}
