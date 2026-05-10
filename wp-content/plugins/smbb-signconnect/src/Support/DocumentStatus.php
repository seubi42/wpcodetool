<?php

namespace Smbb\SignConnect\Support;

defined('ABSPATH') || exit;

final class DocumentStatus
{
    const DRAFT = 'draft';
    const ZONE_READY = 'zone_ready';
    const READY_TO_SEND = 'ready_to_send';
    const SENT = 'sent';
    const SIGNED = 'signed';
    const REFUSED = 'refused';
    const EXPIRED = 'expired';
    const EXPIRED_DELETED = 'expired_deleted';

    public static function normalize($status)
    {
        $status = is_string($status) ? trim($status) : '';

        return $status !== '' ? $status : self::DRAFT;
    }

    public static function isTerminal($status)
    {
        return in_array(self::normalize($status), array(self::SIGNED, self::REFUSED, self::EXPIRED, self::EXPIRED_DELETED), true);
    }

    public static function canPrepareSend($status)
    {
        return in_array(self::normalize($status), array(self::DRAFT, self::ZONE_READY, self::READY_TO_SEND), true);
    }

    public static function canSend($status)
    {
        return in_array(self::normalize($status), array(self::READY_TO_SEND, self::SENT), true);
    }

    public static function canPublicAnswer($status)
    {
        return self::normalize($status) === self::SENT;
    }

    public static function label($status)
    {
        switch (self::normalize($status)) {
            case self::ZONE_READY:
                return __('Signature area ready', 'smbb-signconnect');
            case self::READY_TO_SEND:
                return __('Ready to send', 'smbb-signconnect');
            case self::SENT:
                return __('Sent', 'smbb-signconnect');
            case self::SIGNED:
                return __('Signed', 'smbb-signconnect');
            case self::REFUSED:
                return __('Refused', 'smbb-signconnect');
            case self::EXPIRED:
                return __('Expired', 'smbb-signconnect');
            case self::EXPIRED_DELETED:
                return __('Expired and deleted', 'smbb-signconnect');
            case self::DRAFT:
            default:
                return __('Draft', 'smbb-signconnect');
        }
    }
}
