<?php

namespace Omnibill\Pennylane;

use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\ProviderException;
use Omnibill\Http\Answer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pennylane's Company API v2 (/api/external/v2): a bearer token - a
 * company's API token, a firm's (with X-Company-Id), or a partner app's
 * OAuth access token (OAuth) - JSON both ways, lists paged by cursor
 * ({items, has_more, next_cursor}), filters as a JSON array in the query
 * (filter=[{"field":"number","operator":"eq","value":"512"}]). 25 requests
 * per 5 seconds per token: a 429 is an UnreachableException.
 */
final class Api
{
    public const PROVIDER = 'pennylane';
    public const URL = 'https://app.pennylane.com/api/external/v2';

    /** @param string|null $companyId X-Company-Id: the company a firm token acts for */
    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $token,
        public readonly string $url = self::URL,
        private readonly ?string $companyId = null,
    ) {
    }

    /**
     * @param array<string, mixed>               $query
     * @param list<array{string, string, mixed}> $filters field, operator, value
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], array $filters = []): array
    {
        if ($filters) {
            $query['filter'] = json_encode(array_map(static fn (array $f) => ['field' => $f[0], 'operator' => $f[1], 'value' => $f[2]], $filters), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }

        return $this->call('GET', $path, $query ? ['query' => array_filter($query, static fn ($v) => null !== $v)] : []);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function send(string $method, string $path, array $body): array
    {
        return $this->call($method, $path, ['json' => $body]);
    }

    /**
     * Every item of a list, following its cursor.
     *
     * @param array<string, mixed>               $query
     * @param list<array{string, string, mixed}> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $path, array $query = [], array $filters = []): array
    {
        $items = [];
        $cursor = null;
        do {
            $page = $this->get($path, $query + ['limit' => 100, 'cursor' => $cursor], $filters);
            array_push($items, ...self::items($page));
            $cursor = \is_string($page['next_cursor'] ?? null) && true === ($page['has_more'] ?? false) ? $page['next_cursor'] : null;
        } while (null !== $cursor);

        return $items;
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    public static function items(array $page): array
    {
        $items = [];
        foreach (\is_array($page['items'] ?? null) ? $page['items'] : [] as $item) {
            if (\is_array($item)) {
                $row = [];
                foreach ($item as $key => $value) {
                    $row[(string) $key] = $value;
                }
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $options): array
    {
        $options['headers'] = ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'] + (null !== $this->companyId ? ['X-Company-Id' => $this->companyId] : []);
        $answer = Answer::send($this->http, self::PROVIDER, $method, $this->url.$path, $options);
        if (\in_array($answer->status, [401, 403], true)) {
            throw new InvalidKeyException(self::PROVIDER, \sprintf('The token was refused (HTTP %d): %s', $answer->status, self::error($answer)), (string) $answer->status);
        }
        if ($answer->status >= 400) {
            $error = json_decode($answer->body, true);
            $code = \is_array($error) && \is_string($error['code'] ?? null) ? $error['code'] : (string) $answer->status;

            throw new ProviderException(self::PROVIDER, \sprintf('%s %s: HTTP %d, %s', $method, $path, $answer->status, self::error($answer)), $code);
        }

        return '' === trim($answer->body) ? [] : $answer->json();
    }

    /** The error, whichever shape it came in: {error, status}, {message, field, code}, {error, message, details}. */
    private static function error(Answer $answer): string
    {
        $error = json_decode($answer->body, true);
        if (!\is_array($error)) {
            return mb_substr(trim($answer->body), 0, 200) ?: 'no detail';
        }
        $parts = [];
        foreach (['error', 'message', 'field'] as $key) {
            if (\is_string($error[$key] ?? null) && !\in_array($error[$key], $parts, true)) {
                $parts[] = $error[$key];
            }
        }

        return $parts ? implode(', ', $parts) : 'an error';
    }
}
