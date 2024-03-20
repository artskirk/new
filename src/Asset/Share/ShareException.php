<?php

namespace Datto\Asset\Share;

use Datto\Asset\AssetException;

class ShareException extends AssetException
{
    const CODE_ALREADY_EXISTS = 245;
}
