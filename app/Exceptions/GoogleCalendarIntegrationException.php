<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleCalendarIntegrationException extends RuntimeException
{
    public const REASON_CONFIG_MISSING = 'calendar_config_missing';

    public const REASON_OAUTH_STATE_INVALID = 'oauth_state_invalid';

    public const REASON_OAUTH_DENIED = 'oauth_denied';

    public const REASON_TOKEN_EXCHANGE_INVALID_CLIENT = 'token_exchange_invalid_client';

    public const REASON_TOKEN_EXCHANGE_REDIRECT_MISMATCH = 'token_exchange_redirect_mismatch';

    public const REASON_TOKEN_EXCHANGE_FAILED = 'token_exchange_failed';

    public const REASON_TOKEN_RESPONSE_INVALID = 'token_response_invalid';

    public const REASON_CONNECTION_PERSISTENCE_FAILED = 'connection_persistence_failed';

    public const REASON_EVENT_INSERT_UNAUTHORIZED = 'event_insert_unauthorized';

    public const REASON_EVENT_INSERT_FORBIDDEN = 'event_insert_forbidden';

    public const REASON_EVENT_INSERT_API_DISABLED = 'event_insert_api_disabled';

    public const REASON_EVENT_INSERT_FAILED = 'event_insert_failed';

    public const REASON_TOKEN_REFRESH_FAILED = 'token_refresh_failed';

    public function __construct(
        string $message,
        public readonly string $reasonCode = self::REASON_EVENT_INSERT_FAILED,
        public readonly bool $disconnect = false,
        public readonly ?int $googleHttpStatus = null,
    ) {
        parent::__construct($message);
    }
}
