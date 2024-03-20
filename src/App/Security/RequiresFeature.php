<?php

namespace Datto\App\Security;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted as SensioIsGranted;

/**
 * Makes annotations easier to read by using a Datto namespace
 *
 * @Annotation
 */
class RequiresFeature extends SensioIsGranted
{
    // Hello? No feature, what?!
}
