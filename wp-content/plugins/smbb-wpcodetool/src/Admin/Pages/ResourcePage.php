<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

final class ResourcePage extends AbstractAdminPage
{
    public function render(ResourceDefinition $resource)
    {
        $manager = $this->manager();
        $request = $manager->request();
        $state = $manager->state();

        if (!current_user_can($resource->capability())) {
            wp_die(esc_html__('You are not allowed to access this CodeTool page.', 'smbb-wpcodetool'));
        }

        $manager->addNoticeFromQuery();

        $default_view = $resource->defaultAdminView();
        $view = $state->forcedView() !== '' ? $state->forcedView() : $request->queryKey('view', $default_view);

        if ($request->queryText('action') !== '') {
            $action = $request->queryKey('action');

            if (!in_array($action, array('delete', 'duplicate'), true)) {
                $manager->addRuntimeNotice('info', __('This admin action is not implemented yet.', 'smbb-wpcodetool'));
            }
        }

        $view_path = $resource->viewPath($view);

        if (!$view_path || !is_readable($view_path)) {
            $manager->renderMissingView($resource, $view, $view_path);
            return;
        }

        $manager->renderResourceView($resource, $view, $view_path);
    }
}
