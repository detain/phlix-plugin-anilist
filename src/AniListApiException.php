<?php

/**
 * AniList API Exception.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\AniList;

/**
 * Exception for AniList API errors.
 *
 * Carries the failure as TYPED data where the transport knows it: the HTTP
 * status of an answered request, and the documented not-found answer.
 * Classification of "no such entry" reads these fields, never message prose
 * — the message embeds remote-controlled error text, and a proxy page or
 * GraphQL complaint that merely mentions "not found" must not be mistaken
 * for definitive absence (the lookup wrappers memoize null as "no entry").
 *
 * @package Phlix\Plugins\Metadata\AniList
 */
class AniListApiException extends \RuntimeException
{
    /**
     * @param string      $message     Human-readable error text (log-safe to print)
     * @param int|null    $httpStatus  HTTP status when a request was answered
     *                                 (null for transport, envelope and policy failures)
     * @param bool        $notFound    True when a GraphQL error envelope carried the
     *                                 documented "Not found" answer on an HTTP 200 body
     * @param \Throwable|null $previous Chaining cause, if any
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly bool $notFound = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The HTTP status that answered the request, or null when no HTTP
     * response was involved (transport failure, timeout, GraphQL envelope).
     */
    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Whether upstream definitively answered "no such entry".
     *
     * AniList signals absence two documented ways: a bare HTTP 404 from the
     * transport, and an HTTP 200 GraphQL envelope whose error message is
     * exactly "Not found". Both are absence of data, not API failure; the
     * lookup wrappers map them to null instead of throwing. Every other
     * fault — including prose that merely contains the words — throws.
     */
    public function isNotFound(): bool
    {
        return $this->notFound === true || $this->httpStatus === 404;
    }
}
