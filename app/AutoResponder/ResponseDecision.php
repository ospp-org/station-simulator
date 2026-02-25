<?php

declare(strict_types=1);

namespace App\AutoResponder;

enum ResponseDecision: string
{
    case ACCEPTED = 'Accepted';
    case REJECTED = 'Rejected';
    case REBOOT_REQUIRED = 'RebootRequired';
    case NOT_SUPPORTED = 'NotSupported';
}
