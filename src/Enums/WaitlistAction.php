<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

/**
 * What a request asks the useWaitlist gate for. Token links are not among
 * them: the token is the proof there, and they carry no credentials.
 */
enum WaitlistAction: string
{
    case Subscribe = 'subscribe';

    /** The wording a form shows. */
    case ViewPurposes = 'view-purposes';

    /** A manage link requested by address; by unsubscribe token it needs no gate. */
    case RequestManageLink = 'request-manage-link';

    /** Asked after Subscribe when a signup carries text. */
    case RegisterWording = 'register-wording';
}
