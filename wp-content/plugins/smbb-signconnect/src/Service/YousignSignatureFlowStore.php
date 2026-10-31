<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

final class YousignSignatureFlowStore
{
    private const OPTION_NAME = 'smbb_signconnect_yousign_signature_flows';

    public function get($document_id)
    {
        $flows = $this->all();
        $key = (string) absint($document_id);

        return isset($flows[$key]) && is_array($flows[$key]) ? $flows[$key] : array();
    }

    public function save($document_id, array $flow)
    {
        $flows = $this->all();
        $flows[(string) absint($document_id)] = array_merge($flow, array(
            'updated_at' => gmdate('c'),
        ));

        return update_option(self::OPTION_NAME, $flows, false);
    }

    public function delete($document_id)
    {
        $flows = $this->all();
        $key = (string) absint($document_id);

        if (!isset($flows[$key])) {
            return true;
        }

        unset($flows[$key]);

        return update_option(self::OPTION_NAME, $flows, false);
    }

    public function all()
    {
        $flows = get_option(self::OPTION_NAME, array());

        return is_array($flows) ? $flows : array();
    }
}
