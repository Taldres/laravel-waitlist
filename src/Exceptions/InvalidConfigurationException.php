<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Exceptions;

use InvalidArgumentException;

/**
 * The waitlist is set up wrong: a project definition, a config value or a
 * binding. Fix it in code or config; nothing should catch this to carry on.
 * Not a WaitlistException, which callers answer as invalid input, so a broken
 * setup never reaches a visitor as a form error.
 */
class InvalidConfigurationException extends InvalidArgumentException {}
