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

namespace webservice_elediamcp\local\ai;

use Exception;

/**
 * Exception used by AI tools to signal a business error.
 *
 * Distinguished from generic exceptions so that {@see \webservice_elediamcp\local\server}
 * can convert them into structured MCP tool execution errors (isError: true)
 * rather than JSON-RPC protocol errors. LLMs are then able to self-correct
 * and retry with adjusted arguments.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_exception extends Exception {
    /** @var array<string,mixed> Optional structured context for the LLM. */
    private array $context;

    /**
     * Constructor.
     *
     * @param string $message Human-readable, LLM-friendly message.
     * @param array $context Optional structured context.
     * @param int $code Optional error code.
     */
    public function __construct(string $message, array $context = [], int $code = 0) {
        parent::__construct($message, $code);
        $this->context = $context;
    }

    /**
     * Return the structured context attached to the exception.
     *
     * @return array<string,mixed>
     */
    public function get_context(): array {
        return $this->context;
    }
}
