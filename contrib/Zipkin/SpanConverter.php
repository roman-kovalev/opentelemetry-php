<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Zipkin;

use function max;
use OpenTelemetry\Sdk\Trace\Clock;
use OpenTelemetry\Sdk\Trace\SpanData;

class SpanConverter
{
    const STATUS_CODE_TAG_KEY = 'op.status_code';
    const STATUS_DESCRIPTION_TAG_KEY = 'op.status_description';
    /**
     * @var string
     */
    private $serviceName;

    public function __construct(string $serviceName)
    {
        $this->serviceName = $serviceName;
    }

    private function sanitiseTagValue($value)
    {
        // Casting false to string makes an empty string
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        // Zipkin tags must be strings, but opentelemetry
        // accepts strings, booleans, numbers, and lists of each.
        if (is_array($value)) {
            return implode(',', array_map([$this, 'sanitiseTagValue'], $value));
        }

        // Floats will lose precision if their string representation
        // is >=14 or >=17 digits, depending on PHP settings.
        // Can also throw E_RECOVERABLE_ERROR if $value is an object
        // without a __toString() method.
        // This is possible because OpenTelemetry\Trace\Span does not verify
        // setAttribute() $value input.
        return (string) $value;
    }

    public function convert(SpanData $span)
    {
        $spanParent = $span->getParentContext();

        $startTimestamp = Clock::nanosToMicro($span->getStartEpochNanos());
        $endTimestamp = Clock::nanosToMicro($span->getEndEpochNanos());

        $row = [
            'id' => $span->getSpanId(),
            'traceId' => $span->getTraceId(),
            'parentId' => $spanParent->isValid() ? $spanParent->getSpanId() : null,
            'localEndpoint' => [
                'serviceName' => $this->serviceName,
            ],
            'name' => $span->getName(),
            'timestamp' => $startTimestamp,
            'duration' => max(1, $endTimestamp - $startTimestamp),
            'tags' => [
                self::STATUS_CODE_TAG_KEY => $span->getStatus()->getCode(),
                self::STATUS_DESCRIPTION_TAG_KEY => $span->getStatus()->getDescription(),
            ],
        ];

        foreach ($span->getAttributes() as $k => $v) {
            $row['tags'][$k] = $this->sanitiseTagValue($v->getValue());
        }

        foreach ($span->getEvents() as $event) {
            if (!array_key_exists('annotations', $row)) {
                $row['annotations'] = [];
            }
            $row['annotations'][] = [
                'timestamp' => Clock::nanosToMicro($event->getEpochNanos()),
                'value' => $event->getName(),
            ];
        }

        return $row;
    }
}
