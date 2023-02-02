<?php

    class SpotifyAPI {
        /**
         * @var string|false Token to executes spotify API requests
         */
        public $token = false;

        /**
         * @param DataBase $db
         * @param boolean $force Force new token generation
         */
        function __construct($db, $force = false) {
            $this->token = $this->GenerateToken($db, $force);
        }

        /**
         * @param string|null $key Use key instead of class token (use for get token)
         */
        function __initCurlWithHeader($key = null) {
            $ch = curl_init();
            if ($ch === false) return false;

            $headers = array();
            if ($key !== null) {
                $headers[] = "Authorization: Basic $key";
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $headers[] = 'Accept: application/json';
                $headers[] = 'Content-Type: application/json';
                $headers[] = "Authorization: Bearer {$this->token}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            return $ch;
        }

        /**
         * @param DataBase $db
         * @param boolean $force Force new token generation
         * @return string|false Token to executes spotify API requests or false if request failed
         */
        function GenerateToken($db, $force = false) {
            $command = 'SELECT `VALUE` FROM TABLE WHERE `NAME` = "SPOTIFY_KEY"';
            $key = $db->QueryPrepare('Info', $command);
            if ($key === false) return false;
            $key = $key[0]['VALUE'];

            if (!$force) {
                $command = 'SELECT `VALUE`, `DATE` FROM TABLE WHERE `NAME` = "SPOTIFY_TOKEN"';
                $result = $db->QueryPrepare('Info', $command);
                if ($result === false) return false;
                $token = $result[0]['VALUE'];
                $tokenDate = $result[0]['DATE'];

                $command = 'SELECT `VALUE` FROM TABLE WHERE `NAME` = "SPOTIFY_TOKEN_DURATION"';
                $tokenDuration = $db->QueryPrepare('Info', $command);
                if ($tokenDuration === false) return false;
                $tokenDuration = !empty($tokenDuration) ? intval($tokenDuration[0]['VALUE']) : 0;

                $timeFromLastToken = time() - strtotime($tokenDate);
                if (!empty($token) && $timeFromLastToken < $tokenDuration) {
                    return $token;
                }
            }

            $ch = $this->__initCurlWithHeader($key);
            if ($ch === false) return false;

            curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                //echo('Error:' . curl_error($ch));
                return false;
            }
            curl_close($ch);

            if ($result === false)
                return false;
            $result = json_decode($result, true);
            if ($result === null || !isset($result['access_token']) || !isset($result['expires_in']))
                return false;

            $token = $result['access_token'];
            $duration = $result['expires_in'];

            // Update token & date
            $command = 'UPDATE TABLE SET `VALUE` = ?, `DATE` = current_timestamp() WHERE `NAME` = "SPOTIFY_TOKEN"';
            $result = $db->QueryPrepare('Info', $command, 's', [ $token ]);
            if ($result === false) return false;

            // Update token duration
            $command = 'UPDATE TABLE SET `VALUE` = ? WHERE `NAME` = "SPOTIFY_TOKEN_DURATION"';
            $result = $db->QueryPrepare('Info', $command, 's', [ $duration ]);
            if ($result === false) return false;

            return $token;
        }

        /**
         * @param string $artist
         * @param 'album'|'artist'|'playlist'|'track'|'show'|'episode'|'audiobook' $type
         * @param int $limit Limit of result
         * @param int $offset
         * @param int $status Status of HTTP request
         * @return mixed|false URL of artist's spotify page or false if request failed
         */
        function Search($artist, $type = 'artist', $limit = 10, $offset = 0, &$status = null) {
            if ($this->token === false) return false;

            $ch = $this->__initCurlWithHeader();
            if ($ch === false) return false;

            $limitMax = 50;
            $options = array(
                'q' => $artist,
                'type' => $type,
                'offset' => $offset,
                'limit' => min($limit, $limitMax)
            );
            $url = 'https://api.spotify.com/v1/search?' . http_build_query($options);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

            $curlResult = curl_exec($ch);
            if ($curlResult === false) {
                return false;
            }
            if (curl_errno($ch)) {
                //echo('Error:' . curl_error($ch)); // TODO: Remove
                return false;
            }
            curl_close($ch);

            $curlInfo = curl_getinfo($ch);
            if (!checkDict($curlInfo, 'http_code')) {
                return false;
            }

            $status = $curlInfo['http_code'];
            if ($status === 401) {
                // Bad token or token expired
                return false;
            }
            else if ($status === 403) {
                // Bad OAuth request (wrong consumer key, bad nonce, expired timestamp...)
                return false;
            }
            else if ($status === 429) {
                // The app has exceeded its rate limits
                return false;
            }
            else if ($status !== 200) {
                // Other errors (400 ?)
                return false;
            }

            $searchResult = json_decode($curlResult, true);
            if ($searchResult === NULL || !checkDict($searchResult, "{$type}s", 'items'))
                return false;
            $searchResult = $searchResult["{$type}s"]['items'];

            if ($limit > $limitMax && count($searchResult) === $limitMax) {
                $next = $this->Search($artist, $type, $limit - $limitMax, $offset + $limitMax);
                if ($next === false) return [];
                array_push($searchResult, ...$next);
            }

            return $searchResult;
        }

        function GetArtistAlbums($artistID, &$status = null) {
            if ($this->token === false) return false;

            $ch = $this->__initCurlWithHeader();
            if ($ch === false) return false;

            curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/artists/$artistID/albums");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                //echo 'Error:' . curl_error($ch);
                return false;
            }
            curl_close($ch);

            if ($result === false)
                return false;
            $result = json_decode($result, true);
            //if ($result === null || !isset($result['albums']))
            //    return false;

            return $result;
        }

        function GetAlbumTracks($albumIDs, &$status = null) {
            if ($this->token === false) return false;

            $ch = $this->__initCurlWithHeader();
            if ($ch === false) return false;

            $options = array('ids' => join(',', $albumIDs));
            curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/albums?' . http_build_query($options));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                //echo 'Error:' . curl_error($ch);
                return false;
            }
            curl_close($ch);

            if ($result === false)
                return false;
            $result = json_decode($result, true);
            if ($result === null || !isset($result['albums']))
                return false;

            return $result['albums'];
        }

        /**
         * @param string[] $IDs
         * @param int $status Status of HTTP request
         * @return mixed|false URL of artist's spotify page or false if request failed
         */
        function GetAudioFeature($IDs, &$status = null) {
            if ($this->token === false) return false;

            $ch = $this->__initCurlWithHeader();
            if ($ch === false) return false;

            $options = array('ids' => join(',', $IDs));
            curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/audio-features?' . http_build_query($options));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

            $result = curl_exec($ch);
            if (curl_errno($ch)) {
                //echo 'Error:' . curl_error($ch);
                return false;
            }
            curl_close($ch);

            $curlInfo = curl_getinfo($ch);
            if (!checkDict($curlInfo, 'http_code'))
                return false;

            $status = $curlInfo['http_code'];
            if ($status === 401) {
                // Bad token or token expired
                return false;
            }
            if ($status === 403) {
                // Bad OAuth request (wrong consumer key, bad nonce, expired timestamp...)
                return false;
            }
            if ($status === 429) {
                // The app has exceeded its rate limits
                return false;
            }

            $audioFeatures = json_decode($result, true);
            if ($audioFeatures === null || !checkDict($audioFeatures, 'audio_features'))
                return false;

            return $audioFeatures['audio_features'];
        }

        /**
         * Download track from Spotify (320k bitrate)
         * @param string $id ID of track and name of file
         * @param string $directory Directory to save file
         * @return boolean True if download success or file exists, else false
         */
        function Download($id, $directory = './') {
            if (!str_ends_with($directory, '/')) $directory .= '/';

            if ($this->token === false) return false;
            if (file_exists($directory . $id . '.mp3')) return true;

            $command = "python3 -m spotdl --auth-token {$this->token} https://open.spotify.com/track/$id --output $directory{track-id} --bitrate 320k";
            $status = bash($command);
            return $status === 0;
        }
    }

?>