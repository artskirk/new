<?php

namespace Datto\App\Security;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted as SensioIsGranted;

/**
 * Makes annotations easier to read by using a Datto namespace
 *
 * @author John Fury Christ <jchrist@datto.com>
 * @Annotation
 */
class RequiresPermission extends SensioIsGranted
{
    // You really need dat perm, yo!
}
