<?php

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
 * @param 'basic'|'bearer' $authType Authorization type
 * @param string $tokenOrKey Spotify token or key (use for get token)
 * @return \CurlHandle|false
 */
function InitSpotifyCurlWithHeader($authType, $tokenOrKey) {
    $ch = curl_init();
    if ($ch === false) return false;

    $headers = array();
    if ($authType === 'basic') {
        $headers[] = "Authorization: Basic {$tokenOrKey}"; // Key
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } else if ($authType === 'bearer') {
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: application/json';
        $headers[] = "Authorization: Bearer {$tokenOrKey}"; // Token
    } else {
        return false;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    return $ch;
}

?>
