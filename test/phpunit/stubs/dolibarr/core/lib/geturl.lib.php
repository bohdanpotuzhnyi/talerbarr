<?php

// Global storage used by tests.
$GLOBALS['MOCK_HTTP_QUEUE'] = [];
$GLOBALS['MOCK_HTTP_LAST']  = [
    'url'     => null,
    'method'  => null,
    'payload' => null,
    'headers' => null,
    'args'    => null,
];

/**
 * Push a mock response. Helper used by tests via require of this file.
 */
function _mock_http_queue_push(array $response): void {
    $GLOBALS['MOCK_HTTP_QUEUE'][] = $response;
}

/**
 * Pop next response from the queue or return a default.
 */
function _mock_http_queue_pop(): array {
    if (!empty($GLOBALS['MOCK_HTTP_QUEUE'])) {
        return array_shift($GLOBALS['MOCK_HTTP_QUEUE']);
    }
    // Default: 200 OK empty body
    return ['http_code' => 200, 'content' => ''];
}

/**
 * Dolibarr's getURLContent() signature (simplified to what's used by client).
 * We capture all inputs so tests can assert them.
 */
function getURLContent($url, $method = 'GET', $parameters = '', $followlocation = 1, $addheaders = [], $allowed_schemes = ['http','https'], $disablesslchecks = 2, $proxy = -1, $connecttimeout = 5, $timeout = 10)
{
    $GLOBALS['MOCK_HTTP_LAST'] = [
        'url'     => $url,
        'method'  => $method,
        'payload' => $parameters,
        'headers' => $addheaders,
        'args'    => [
            'followlocation' => $followlocation,
            'allowed' => $allowed_schemes,
            'ssl'     => $disablesslchecks,
            'proxy'   => $proxy,
            'ct'      => $connecttimeout,
            't'       => $timeout,
        ],
    ];

    $resp = _mock_http_queue_pop();

    // Convert to the array shape your client expects.
    $out = [
        'http_code'      => (int)($resp['http_code'] ?? 200),
        'content'        => (string)($resp['content'] ?? ''),
        'curl_error_no'  => (int)($resp['curl_error_no'] ?? 0),
        'curl_error_msg' => (string)($resp['curl_error_msg'] ?? ''),
    ];
    return $out;
}