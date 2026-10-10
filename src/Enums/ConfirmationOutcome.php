<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

/**
 * What the listener reported about the latest confirmation request of a cycle.
 * Null until a report arrives, and again for every new request.
 */
enum ConfirmationOutcome: string
{
    /** The mail went out. A later failure report is ignored: the mail is out. */
    case Mailed = 'mailed';

    /** The listener gave up. A later report that the mail went out after all is accepted, once. */
    case Failed = 'failed';
}
