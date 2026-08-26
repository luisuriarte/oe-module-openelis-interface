<?php

namespace OpenEMR\Modules\OpenElis\Client;

class OpenElisApiException extends \RuntimeException
{
    private int $httpStatus;
    private string $responseBody;

    public function __construct(int $httpStatus, string $responseBody, ?\Throwable $previous = null)
    {
        $this->httpStatus = $httpStatus;
        $this->responseBody = $responseBody;

        $message = "OpenELIS API error (HTTP $httpStatus): " . substr($responseBody, 0, 500);
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
