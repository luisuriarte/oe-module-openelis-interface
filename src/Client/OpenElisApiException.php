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

        $detail = self::parseOutcomeDetail($responseBody);
        $message = "OpenELIS API error (HTTP $httpStatus)" . ($detail !== '' ? ": $detail" : '');
        parent::__construct($message, $httpStatus, $previous);
    }

    public static function parseOutcomeDetail(string $responseBody): string
    {
        $body = trim($responseBody);
        if ($body === '') {
            return '';
        }

        $json = json_decode($body, true);
        if (is_array($json) && ($json['resourceType'] ?? '') === 'OperationOutcome') {
            $issues = [];
            foreach ($json['issue'] ?? [] as $issue) {
                if (!empty($issue['diagnostics'])) {
                    $issues[] = $issue['diagnostics'];
                } elseif (!empty($issue['details']['text'])) {
                    $issues[] = $issue['details']['text'];
                }
            }
            if (!empty($issues)) {
                return implode('; ', $issues);
            }
        }

        return substr(strip_tags($body), 0, 300);
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
