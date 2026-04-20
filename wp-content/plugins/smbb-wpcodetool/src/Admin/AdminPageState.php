<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Mutable per-request state for CodeTool admin screens.
 */
final class AdminPageState
{
    private $notices = array();
    private $item_override = null;
    private $form_errors = array();
    private $forced_view = '';

    /**
     * Resets the transient state used while handling one admin request.
     */
    public function reset()
    {
        $this->notices = array();
        $this->item_override = null;
        $this->form_errors = array();
        $this->forced_view = '';
    }

    /**
     * Adds a notice to render later in the page.
     *
     * @param array<int|string,string> $details
     */
    public function addNotice($type, $message, array $details = array())
    {
        $this->notices[] = array(
            'type' => $type,
            'message' => $message,
            'details' => $details,
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function notices()
    {
        return $this->notices;
    }

    /**
     * @param array<string,mixed>|null $item
     */
    public function setItemOverride($item)
    {
        $this->item_override = is_array($item) ? $item : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function itemOverride()
    {
        return $this->item_override;
    }

    /**
     * @param array<string,mixed> $errors
     */
    public function setFormErrors(array $errors)
    {
        $this->form_errors = $errors;
    }

    /**
     * @return array<string,mixed>
     */
    public function formErrors()
    {
        return $this->form_errors;
    }

    /**
     * Forces the resource page to render a specific view.
     */
    public function setForcedView($view)
    {
        $this->forced_view = sanitize_key((string) $view);
    }

    /**
     * Returns the forced resource view for the current request.
     */
    public function forcedView()
    {
        return $this->forced_view;
    }
}
