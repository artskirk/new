<?php

namespace Datto\Iscsi;

use Eloquent\Enumeration\AbstractEnumeration;

/**
 * @method static INCOMING
 * @method static OUTGOING
 */
class UserType extends AbstractEnumeration
{
    const INCOMING = 'IncomingUser';
    const OUTGOING = 'OutgoingUser';
}
