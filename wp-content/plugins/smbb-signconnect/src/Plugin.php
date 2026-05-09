<?php

namespace Smbb\SignConnect;

use Smbb\SignConnect\Shortcode\SignConnectPostShortcode;
use Smbb\SignConnect\Shortcode\SignConnectDashboardShortcode;
use Smbb\SignConnect\Shortcode\SignConnectSignShortcode;
use Smbb\SignConnect\User\UserStorageProfileFields;

defined('ABSPATH') || exit;

final class Plugin
{
    public function hooks()
    {
        (new UserStorageProfileFields())->hooks();
        (new SignConnectPostShortcode())->hooks();
        (new SignConnectSignShortcode())->hooks();
        (new SignConnectDashboardShortcode())->hooks();
    }
}
