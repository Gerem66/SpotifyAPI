<?php

/**
 * Create own 'str_ends_with' function if not exists
 * @param string $haystack String to search in
 * @param string $needle String to search
 * @return bool
 */
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    function str_ends_with($haystack, $needle) {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return (substr($haystack, -$length) === $needle);
    }
}

/**
 * @param array $dict Dictionary
 * @param string $cells Chain of keys
 * @return bool
 */
function checkDict($dict, ...$cells) {
    foreach ($cells as $cell) {
        if (!isset($dict[$cell]))
            return false;
        $dict = $dict[$cell];
    }
    return true;
}

/**
 * @param 'basic'|'bearer' $auth_type Authorization type
 * @param string $token_or_key Spotify key (with basic auth, used for get token) or token (with bearer auth)
 * @return \CurlHandle|false
 */
function InitSpotifyCurlWithHeader($auth_type, $token_or_key) {
    $headers = array();
    if ($auth_type === 'basic') {
        $headers[] = "Authorization: Basic {$token_or_key}"; // Key
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else if ($auth_type === 'bearer') {
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: application/json';
        $headers[] = "Authorization: Bearer {$token_or_key}"; // Token
    } else {
        return false;
    }

    $ch = curl_init();
    if ($ch === false) return false;

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    return $ch;
}

?>
