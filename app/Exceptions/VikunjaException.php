<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the Vikunja API is unreachable or answers with an error.
 *
 * Always caught at the service boundary: the Kanban board is a convenience, not
 * a dependency of monitoring. A dead Vikunja must never fail an insight run or
 * lose an alert — it only delays the card, which the next sweep will create.
 */
class VikunjaException extends RuntimeException {}
