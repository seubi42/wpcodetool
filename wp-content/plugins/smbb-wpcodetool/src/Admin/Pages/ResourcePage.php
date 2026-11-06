<?php

namespace Smbb\WpCodeTool\Admin\Pages;

use Smbb\WpCodeTool\Resource\ResourceDefinition;

defined('ABSPATH') || exit;

/**
 * Rend l'ecran principal d'une ressource CodeTool.
 */
final class ResourcePage extends AbstractAdminPage
{
    public function render(ResourceDefinition $resource)
    {
        $manager = $this->manager();
        $request = $manager->request();
        $state = $manager->state();

        if (!current_user_can($resource->capability())) {
            wp_die(esc_html__('Vous n\'avez pas accès à cette page CodeTool.', 'smbb-wpcodetool'));
        }

        $manager->addNoticeFromQuery();

        $default_view = $resource->defaultAdminView();
        $view = $state->forcedView() !== '' ? $state->forcedView() : $request->queryKey('view', $default_view);

        if ($request->queryText('action') !== '') {
            $action = $request->queryKey('action');

            if (!in_array($action, array('delete', 'duplicate'), true)) {
                $manager->addRuntimeNotice('info', __('Cette action d\'admin n\'est pas encore disponible.', 'smbb-wpcodetool'));
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
