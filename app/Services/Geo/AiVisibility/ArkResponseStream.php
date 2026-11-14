<?php

namespace App\Services\Geo\AiVisibility;

use App\Services\Outbound\OutboundRequestBlockedException;
use Closure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/** Bounded SSE sink; persists response.created before a possible stream interruption. */
final class ArkResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $bytes = 0;
    private string $buffer = '';
    private ?string $responseId = null;
    private ?array $response = null;

    public function __construct(private readonly int $maxBytes, private readonly Closure $onResponseId)
    {
        $this->stream = Utils::streamFor(fopen('php://temp', 'w+'));
    }

    public function write($string): int
    {
        $this->bytes += strlen($string);
        if ($this->bytes > $this->maxBytes) {
            throw new OutboundRequestBlockedException('response_too_large');
        }
        $this->buffer = str_replace("\r\n", "\n", $this->buffer.$string);
        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $frame = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 2);
            $lines = [];
            foreach (explode("\n", $frame) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $lines[] = ltrim(substr($line, 5), ' ');
                }
            }
            $data = implode("\n", $lines);
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($event)) {
                throw new \UnexpectedValueException('Invalid response event.');
            }
            // https://docs.volcengine.com/docs/ark/response-lifecycle?lang=zh
            $type = $event['type'] ?? '';
            if (! in_array($type, ['response.created', 'response.completed', 'response.incomplete', 'response.failed'], true)) {
                continue;
            }
            $response = $event['response'] ?? null;
            $id = is_array($response) ? ($response['id'] ?? null) : null;
            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $id)
                || ($this->responseId !== null && $this->responseId !== $id)) {
                throw new \UnexpectedValueException('Response identity is not consistent.');
            }
            if ($this->responseId === null) {
                ($this->onResponseId)($id);
                $this->responseId = $id;
            }
            if ($type !== 'response.created') {
                $this->response = $response;
            }
        }

        return $this->stream->write($string);
    }

    public function response(): ?array
    {
        return $this->response;
    }
}
