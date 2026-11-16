<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an AI agent's role does not allow the attempted action.
 */
class AgentNotAuthorizedException extends RuntimeException {}
