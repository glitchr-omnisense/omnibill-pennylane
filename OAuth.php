<?php

namespace Omnibill\Pennylane;

use Omnibill\Exception\InvalidKeyException;
use Omnibill\Http\Answer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pennylane's OAuth 2.0, for a partner's app (a client id and secret from
 * Pennylane's partnerships team): the authorization page, the code
 * exchanged, the token refreshed. The access token lasts 24 hours, the
 * refresh token 90 days, and each refresh replaces both - never refresh
 * twice at once; invalid_grant means the company must authorize again. The
 * access token is the gateway's `token`.
 */
final class OAuth
{
    public const AUTHORIZE_URL = 'https://app.pennylane.com/oauth/authorize';
    public const TOKEN_URL = 'https://app.pennylane.com/oauth/token';
    public const REVOKE_URL = 'https://app.pennylane.com/oauth/revoke';
    /** The scopes the gateway's calls need. */
    public const SCOPES = ['ledger_entries:all', 'ledger_accounts:readonly', 'journals:readonly', 'customers:all', 'suppliers:all'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
    ) {
    }

    /** @param list<string> $scopes */
    public function authorizeUrl(string $redirectUri, string $state, array $scopes = self::SCOPES): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query(['client_id' => $this->clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => implode(' ', $scopes), 'state' => $state], '', '&', \PHP_QUERY_RFC3986);
    }

    /** @return array{access_token: string, refresh_token: string|null, expires_in: int} */
    public function exchange(string $code, string $redirectUri): array
    {
        return $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    /** @return array{access_token: string, refresh_token: string|null, expires_in: int} */
    public function refresh(#[\SensitiveParameter] string $refreshToken): array
    {
        return $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * @param array<string, string> $form
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    private function token(#[\SensitiveParameter] array $form): array
    {
        $answer = Answer::send($this->http, Api::PROVIDER, 'POST', self::TOKEN_URL, ['body' => ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret] + $form]);
        $data = json_decode($answer->body, true);
        if ($answer->status >= 400 || !\is_array($data) || !\is_string($data['access_token'] ?? null)) {
            $error = \is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : null;

            throw new InvalidKeyException(Api::PROVIDER, \sprintf('The %s was refused (HTTP %d): %s', $form['grant_type'], $answer->status, $error ?? 'no access_token'), $error);
        }

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => \is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : null,
            'expires_in' => \is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 86400,
        ];
    }
}
