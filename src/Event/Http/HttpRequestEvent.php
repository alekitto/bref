<?php declare(strict_types=1);

namespace Bref\Event\Http;

use Bref\Event\InvalidLambdaEvent;
use Bref\Event\LambdaEvent;

/**
 * Represents a Lambda event that comes from a HTTP request.
 *
 * The event can come from API Gateway or ALB (Application Load Balancer).
 *
 * See the following for details on the JSON payloads for HTTP APIs;
 * https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api-develop-integrations-lambda.html#http-api-develop-integrations-lambda.proxy-format
 */
final class HttpRequestEvent implements LambdaEvent
{
    private const PAYLOAD_VERSION_1_0 = 1;
    private const PAYLOAD_VERSION_2_0 = 2;

    /** @var array */
    private $event;
    /** @var string */
    private $method;
    /** @var array */
    private $headers;
    /** @var string */
    private $queryString;
    /** @var int */
    private $payloadVersion;

    /**
     * @param mixed $event
     */
    public function __construct($event)
    {
        // version 1.0 of the HTTP payload
        if (isset($event['httpMethod'])) {
            $this->method = strtoupper($event['httpMethod']);
        } elseif (isset($event['requestContext']['http']['method'])) {
            // version 2.0 - https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api-develop-integrations-lambda.html#http-api-develop-integrations-lambda.proxy-format
            $this->method = strtoupper($event['requestContext']['http']['method']);
        } else {
            throw new InvalidLambdaEvent('API Gateway or ALB', $event);
        }

        $this->payloadVersion = ($event['version'] ?? '1.0') === '1.0' ? self::PAYLOAD_VERSION_1_0 : self::PAYLOAD_VERSION_2_0;
        $this->event = $event;
        $this->queryString = $this->rebuildQueryString();
        $this->headers = $this->extractHeaders();
    }

    public function toArray(): array
    {
        return $this->event;
    }

    public function getBody(): string
    {
        $requestBody = $this->event['body'] ?? '';
        if ($this->event['isBase64Encoded'] ?? false) {
            $requestBody = base64_decode($requestBody);
        }

        return $requestBody;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasMultiHeader(): bool
    {
        if ($this->isFormatV2()) {
            return false;
        }

        return isset($this->event['multiValueHeaders']);
    }

    public function getProtocol(): string
    {
        return $this->event['requestContext']['protocol'] ?? 'HTTP/1.1';
    }

    public function getProtocolVersion(): string
    {
        return explode('/', $this->getProtocol(), 2)[1] ?? '';
    }

    public function getContentType(): ?string
    {
        return $this->headers['content-type'][0] ?? null;
    }

    public function getRemotePort(): int
    {
        return (int) ($this->headers['x-forwarded-port'][0] ?? 80);
    }

    public function getServerPort(): int
    {
        return (int) ($this->headers['x-forwarded-port'][0] ?? 80);
    }

    public function getServerName(): string
    {
        return $this->headers['host'][0] ?? 'localhost';
    }

    public function getPath(): string
    {
        if ($this->isFormatV2()) {
            return $this->event['rawPath'] ?? '/';
        }

        /**
         * $event['path'] contains the URL always without the stage prefix.
         * $event['requestContext']['path'] contains the URL always with the stage prefix.
         * None of the represents the real URL because:
         * - the native API Gateway URL has the stage (`/dev`)
         * - with a custom domain, the URL doesn't have the stage (`/`)
         * - with CloudFront in front of AG, the URL doesn't have the stage (`/`)
         * Because it's hard to detect whether CloudFront is used, we will go with the "non-prefixed" URL ($event['path'])
         * as it's the one most likely used in production (because in production we use custom domains).
         * Since Bref now recommends HTTP APIs (that don't have a stage prefix), this problem will not be common anyway.
         * Full history:
         * - https://github.com/brefphp/bref/issues/67
         * - https://github.com/brefphp/bref/issues/309
         * - https://github.com/brefphp/bref/pull/794
         */
        return $this->event['path'] ?? '/';
    }

    public function getUri(): string
    {
        $queryString = $this->queryString;
        $uri = $this->getPath();
        if (! empty($queryString)) {
            $uri .= '?' . $queryString;
        }
        return $uri;
    }

    public function getQueryString(): string
    {
        return $this->queryString;
    }

    public function getQueryParameters(): array
    {
        parse_str($this->queryString, $query);
        return $query;
    }

    public function getRequestContext(): array
    {
        return $this->event['requestContext'] ?? [];
    }

    public function getCookies(): array
    {
        if ($this->isFormatV2()) {
            $cookieParts = $this->event['cookies'] ?? [];
        } else {
            if (! isset($this->headers['cookie'])) {
                return [];
            }
            // Multiple "Cookie" headers are not authorized
            // https://stackoverflow.com/questions/16305814/are-multiple-cookie-headers-allowed-in-an-http-request
            $cookieHeader = $this->headers['cookie'][0];
            $cookieParts = explode('; ', $cookieHeader);
        }

        $cookies = [];
        foreach ($cookieParts as $cookiePart) {
            [$cookieName, $cookieValue] = explode('=', $cookiePart, 2);
            $cookies[$cookieName] = urldecode($cookieValue);
        }
        return $cookies;
    }

    /**
     * @return array<string,string>
     */
    public function getPathParameters(): array
    {
        return $this->event['pathParameters'] ?? [];
    }

    public function getSourceIp(): string
    {
        if ($this->isFormatV2()) {
            return $this->event['requestContext']['http']['sourceIp'] ?? '127.0.0.1';
        }

        return $this->event['requestContext']['identity']['sourceIp'] ?? '127.0.0.1';
    }

    private function rebuildQueryString(): string
    {
        if ($this->isFormatV2()) {
            // We re-parse the query string to make sure it is URL-encoded
            // Why? To match the format we get when using PHP outside of Lambda (we get the query string URL-encoded)
            $queryParameters = self::queryStringToArray($this->event['rawQueryString'] ?? '');
            return http_build_query($queryParameters);
        }

        // It is likely that we do not need to differentiate between API Gateway (Version 1) and ALB. However,
        // it would lead to a breaking change since the current implementation for API Gateway does not
        // support MultiValue query string. This way, the code is fully backward-compatible while
        // offering complete support for multi value query parameters on ALB. Later on there can
        // be a feature flag that allows API Gateway users to opt-in to complete support as well.
        if (isset($this->event['requestContext']) && isset($this->event['requestContext']['elb'])) {
            // AWS differs between ALB with multiValue enabled or not (docs: https://docs.aws.amazon.com/elasticloadbalancing/latest/application/lambda-functions.html#multi-value-headers)
            if (isset($this->event['multiValueQueryStringParameters'])) {
                $queryParameters = $this->event['multiValueQueryStringParameters'];
            } else {
                $queryParameters = $this->event['queryStringParameters'] ?? [];
            }

            $queryString = '';

            // AWS always deliver the list of query parameters as an array. Let's loop through all of the
            // query parameters available and parse them to get their original URL decoded values.
            foreach ($queryParameters as $key => $values) {
                // If multi-value is disabled, $values is a string containing the last parameter sent.
                // If multi-value is enabled, $values is *always* an array containing a list of parameters per key.
                // Even if we only send 1 parameter (e.g. my_param=1), AWS will still send an array [1] for my_param
                // when multi-value is enabled.
                // By forcing $values to be an array, we can be consistent with both scenarios by always parsing
                // all values available on a given key.
                $values = (array) $values;

                // Let's go ahead and undo AWS's work and rebuild the original string that formed the
                // Query Parameters so that php's native function `parse_str` can automatically
                // decode all keys and all values. The result is a PHP array with decoded
                // keys and values. See https://github.com/brefphp/bref/pull/693
                foreach ($values as $value) {
                    $queryString .= $key . '=' . $value . '&';
                }
            }

            // parse_str will automatically `urldecode` any value that needs decoding. This will allow parameters
            // like `?my_param[bref][]=first&my_param[bref][]=second` to properly work. `$decodedQueryParameters`
            // will be an array with parameter names as keys.
            $decodedQueryParameters = self::queryStringToArray($queryString);

            return http_build_query($decodedQueryParameters);
        }

        if (isset($this->event['multiValueQueryStringParameters']) && $this->event['multiValueQueryStringParameters']) {
            $queryParameterStr = [];
            // go through the params and url-encode the values, to build up a complete query-string
            foreach ($this->event['multiValueQueryStringParameters'] as $key => $value) {
                foreach ($value as $v) {
                    $queryParameterStr[] = $key . '=' . urlencode($v);
                }
            }

            // re-parse the query-string so it matches the format used when using PHP outside of Lambda
            // this is particularly important when using multi-value params - eg. myvar[]=2&myvar=3 ... = [2, 3]
            $queryParameters = self::queryStringToArray(implode('&', $queryParameterStr));
            return http_build_query($queryParameters);
        }

        if (empty($this->event['queryStringParameters'])) {
            return '';
        }

        /*
         * Watch out: do not use $event['queryStringParameters'] directly!
         *
         * (that is no longer the case here, but it was in the past with Bref 0.2)
         *
         * queryStringParameters does not handle correctly arrays in parameters
         * ?array[key]=value gives ['array[key]' => 'value'] while we want ['array' => ['key' = > 'value']]
         * In that case we should recreate the original query string and use parse_str which handles correctly arrays
         */
        return http_build_query($this->event['queryStringParameters']);
    }

    private function extractHeaders(): array
    {
        // Normalize headers
        if (isset($this->event['multiValueHeaders'])) {
            $headers = $this->event['multiValueHeaders'];
        } else {
            $headers = $this->event['headers'] ?? [];
            // Turn the headers array into a multi-value array to simplify the code below
            $headers = array_map(static function ($value): array {
                return [$value];
            }, $headers);
        }
        $headers = array_change_key_case($headers, CASE_LOWER);

        $hasBody = ! empty($this->event['body']);
        // See https://stackoverflow.com/a/5519834/245552
        if ($hasBody && ! isset($headers['content-type'])) {
            $headers['content-type'] = ['application/x-www-form-urlencoded'];
        }

        // Auto-add the Content-Length header if it wasn't provided
        // See https://github.com/brefphp/bref/issues/162
        if ($hasBody && ! isset($headers['content-length'])) {
            $headers['content-length'] = [strlen($this->getBody())];
        }

        // Cookies are separated from headers in payload v2, we re-add them in there
        // so that we have the full original HTTP request
        if (! empty($this->event['cookies']) && $this->isFormatV2()) {
            $cookieHeader = implode('; ', $this->event['cookies']);
            $headers['cookie'] = [$cookieHeader];
        }

        return $headers;
    }

    /**
     * See https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api-develop-integrations-lambda.html#http-api-develop-integrations-lambda.proxy-format
     */
    public function isFormatV2(): bool
    {
        return $this->payloadVersion === self::PAYLOAD_VERSION_2_0;
    }

    public static function queryStringToArray(string $queryString): array
    {
        $queryString = trim($queryString);
        $firstChar = $queryString[0] ?? '';
        if ($firstChar === '?' || $firstChar === '#' || $firstChar === '&') {
            $queryString = substr($queryString, 1);
        }

        if (empty($queryString)) {
            return [];
        }

        $parameters = [];
        // Process one parameter at a time, split query string by "&"
        foreach (explode('&', $queryString) as $parameter) {
            if ($parameter === '') {
                // Empty parameter, ignore.
                continue;
            }

            // Search for an equal sign; if not present, the value is null.
            [$key, $value] = explode('=', $parameter, 2) + [null, null];

            // Decode parameters: will be sent encoded from the browser.
            $key = urldecode($key);
            $value = urldecode($value ?? '');

            if (strpos($key, '[') === false) {
                // No need to process it further.
                $parameters[$key] = $value;
                continue;
            }

            // Check if parameter key is well-formed
            $tokens = [strtok($key, '[')];
            $token = strtok('[');
            for (; $token !== false; $token = strtok('[')) {
                if (substr($token, -1) !== ']') {
                    // Token does not end with ']' which means that the parameter is malformed
                    // Append all the remaining tokens to the last one and try to parse that
                    end($tokens);
                    $k = key($tokens);

                    do {
                        $tokens[$k] .= '[' . $token;
                        $token = strtok('[');
                    } while ($token);
                } else {
                    $tokens[] = $token;
                }
            }

            $current = &$parameters;
            foreach ($tokens as $idx => $token) {
                // Search for the first closing char, if found we can discard all the remaining characters of the token
                $closeIdx = strpos($token, ']');
                $token = $closeIdx !== false ? substr($token, 0, $closeIdx) : $token;

                if ($idx === count($tokens) - 1) {
                    // We reached the end of the tokens list. Now add the parameter
                    // value to the appropriate key (push to the array if no key is specified)
                    if ($token === '') {
                        $current[] = $value;
                    } else {
                        $current[$token] = $value;
                    }

                    break;
                }

                // Here's where the magic happens: current is now a pointer to the current-nesting level
                // parameter array. We know that this is not the last token, so we have to create
                // empty arrays if needed, and prepare for the next loop.
                if ($token === '') {
                    // No key, push a new element into the array.
                    $current[] = [];
                    end($current);
                    $nextKey = key($current);
                } else {
                    // Prepare an empty array if needed
                    if (! isset($current[$token])) {
                        $current[$token] = [];
                    }

                    $nextKey = $token;
                }

                // Update the current pointer.
                $current = &$current[$nextKey];
            }
        }

        return $parameters;
    }
}
