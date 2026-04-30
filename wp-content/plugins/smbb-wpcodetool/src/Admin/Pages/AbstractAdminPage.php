<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Admin\AdminManager;

defined('ABSPATH') || exit;

/**
 * Base commune des pages admin CodeTool.
 */
abstract class AbstractAdminPage
{
    private $manager;

    public function __construct(AdminManager $manager)
    {
        $this->manager = $manager;
    }

    protected function manager()
    {
        return $this->manager;
    }
}
