<?php

declare(strict_types=1);

namespace Atlas\Nexus\Text;

use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Closure;
use Generator;
use Illuminate\Support\Collection;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;

/**
 * Class TextRequest
 *
 * Wraps Prism text requests to preserve full feature access while capturing chat thread logs.
 */
class TextRequest
{
    protected bool $completionAttached = false;

    protected ?Closure $userCompleteCallback = null;

    public function __construct(
        private readonly PendingRequest $pendingRequest,
        private readonly ChatThreadLog $chatThreadLog
    ) {}

    public function pendingRequest(): PendingRequest
    {
        return $this->pendingRequest;
    }

    public function chatThreadLog(): ChatThreadLog
    {
        return $this->chatThreadLog;
    }

    /**
     * @param  callable(PendingRequest|null, Collection<int,\Prism\Prism\Contracts\Message>, Response):void  $callback
     */
    public function onComplete(callable $callback): self
    {
        $this->userCompleteCallback = $callback instanceof Closure
            ? $callback
            : Closure::fromCallable($callback);

        return $this;
    }

    public function asText(): ?Response
    {
        $this->attachCompletionHandler();

        return $this->pendingRequest->asText();
    }

    /**
     * @return Generator<\Prism\Prism\Streaming\Events\StreamEvent>
     */
    public function asStream(): Generator
    {
        $this->attachCompletionHandler();

        return $this->pendingRequest->asStream();
    }

    public function asDataStreamResponse(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->attachCompletionHandler();

        return $this->pendingRequest->asDataStreamResponse();
    }

    public function asEventStreamResponse(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->attachCompletionHandler();

        return $this->pendingRequest->asEventStreamResponse();
    }

    /**
     * Proxy Prism text request methods while keeping chaining behavior intact.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'onComplete') {
            return $this->onComplete(...$arguments);
        }

        $result = $this->pendingRequest->{$name}(...$arguments);

        return $result instanceof PendingRequest ? $this : $result;
    }

    public function toRequest(): Request
    {
        $this->attachCompletionHandler();

        return $this->pendingRequest->toRequest();
    }

    protected function attachCompletionHandler(): void
    {
        if ($this->completionAttached) {
            return;
        }

        $callback = $this->userCompleteCallback;

        $this->pendingRequest->onComplete(function (?PendingRequest $request, Collection $messages, Response $response) use ($callback): void {
            $this->chatThreadLog->recordFromResponse($messages, $response->toolResults);

            if ($callback !== null) {
                $callback($request, $messages, $response);
            }
        });

        $this->completionAttached = true;
    }
}
