<?php
/**
 * This file is part of the Rate Limit package.
 *
 * Copyright (c) Nikola Posa
 *
 * For full copyright and license information, please refer to the LICENSE file,
 * located at the package root folder.
 */

declare(strict_types=1);

namespace RateLimit;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RateLimit\Storage\StorageInterface;
use RateLimit\Identity\IdentityGeneratorInterface;
use RateLimit\Options\RequestsPerWindowOptions;

/**
 * @author Nikola Posa <posa.nikola@gmail.com>
 */
final class RequestsPerWindowRateLimiter extends AbstractRateLimiter
{
    const LIMIT_EXCEEDED_HTTP_STATUS_CODE = 429; //HTTP 429 "Too Many Requests" (RFC 6585)

    const HEADER_LIMIT = 'X-RateLimit-Limit';
    const HEADER_REMAINING = 'X-RateLimit-Remaining';
    const HEADER_RESET = 'X-RateLimit-Reset';

    /**
     * @var RequestsPerWindowOptions
     */
    private $options;

    /**
     * @var string
     */
    private $identity;

    /**
     * @var int
     */
    private $current;

    public function __construct(StorageInterface $storage, IdentityGeneratorInterface $identityGenerator, RequestsPerWindowOptions $options)
    {
        parent::__construct($storage, $identityGenerator);
        
        $this->options = $options;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(RequestInterface $request, ResponseInterface $response, callable $out = null)
    {
        $this->resolveIdentity($request);

        $this->initCurrent();

        if ($this->isLimitExceeded()) {
            return $this->onLimitExceeded($request, $response);
        }

        $this->hit();

        return $this->onBelowLimit($request, $response, $out);
    }

    private function resolveIdentity(RequestInterface $request)
    {
        $this->identity = $this->identityGenerator->getIdentity($request);
    }

    private function initCurrent()
    {
        $current = $this->storage->get($this->identity, false);

        if (false === $current) {
            $current = 0;

            $this->storage->set($this->identity, 0, $this->options->getWindow());
        }

        $this->current = $current;
    }

    private function isLimitExceeded() : bool
    {
        return ($this->current > $this->options->getLimit());
    }

    private function hit()
    {
        $this->storage->increment($this->identity, 1);
    }

    private function onLimitExceeded(RequestInterface $request, ResponseInterface $response) : ResponseInterface
    {
        $response = $this
            ->setRateLimitHeaders($response)
            ->withStatus(self::LIMIT_EXCEEDED_HTTP_STATUS_CODE)
        ;

        $limitExceededHandler = $this->options->getLimitExceededHandler();
        $response = $limitExceededHandler($request, $response);

        return $response;
    }

    private function onBelowLimit(RequestInterface $request, ResponseInterface $response, callable $out = null) : ResponseInterface
    {
        $response = $this->setRateLimitHeaders($response);

        return $out ? $out($request, $response) : $response;
    }

    private function setRateLimitHeaders(ResponseInterface $response) : ResponseInterface
    {
        return $response
            ->withHeader(self::HEADER_LIMIT, (string) $this->options->getLimit())
            ->withHeader(self::HEADER_REMAINING, (string) ($this->options->getLimit() - $this->current))
            ->withHeader(self::HEADER_RESET, (string) (time() + $this->storage->ttl($this->identity)))
        ;
    }
}
