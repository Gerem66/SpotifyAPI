<?php

require_once __DIR__ . '/src/utils.php';

class SpotifyAPI {
    /** @var string|false Token to executes spotify API requests */
    public $token = false;

    /** @var string Path to settings file */
    public $settingsPath = __DIR__ . '/config.json';

    private $settings = array(
        'SPOTIFY_KEY' => '',
        'SPOTIFY_TOKEN' => '',
        'SPOTIFY_TOKEN_CREATION' => '',
        'SPOTIFY_TOKEN_DURATION' => ''
    );

    /**
     * SpotifyAPI constructor.
     * @param boolean $force Force new token generation
     * @throws Exception If settings file not found or invalid
     */
    public function __construct($force = false) {
        $this->LoadSettings();
        if ($this->IsTokenExpired() || $force) {
            $this->RefreshToken();
        }
    }

    /**
     * @throws Exception If settings file not found or invalid
     */
    private function LoadSettings() {
        if (!file_exists($this->settingsPath)) {
            throw new Exception("Settings file not found\n");
        }

        $settingsContent = file_get_contents($this->settingsPath);
        $this->settings = json_decode($settingsContent, true);

        if ($this->settings === null) {
            throw new Exception("Settings file is invalid\n");
        }

        $this->token = $this->settings['SPOTIFY_TOKEN'];
    }

    /**
     * @return bool Save settings to file success
     */
    private function SaveSettings() {
        $json = json_encode($this->settings, JSON_PRETTY_PRINT);
        return file_put_contents($this->settingsPath, $json) !== false;
    }

    /**
     * @return bool Token is expired
     */
    public function IsTokenExpired() {
        $tokenIsValide = !empty($this->settings['SPOTIFY_TOKEN']);
        $tokenCreation = $this->settings['SPOTIFY_TOKEN_CREATION'];
        $tokenDuration = $this->settings['SPOTIFY_TOKEN_DURATION'];
        $timeFromLastToken = time() - strtotime($tokenCreation);
        return !$tokenIsValide || $timeFromLastToken >= $tokenDuration - 300; // 5 minutes before expiration
    }

    /**
     * Refresh token and save it to settings file
     * @param int $http_status HTTP status code
     * @return string|false Spotify API token or false if request failed
     */
    public function RefreshToken(&$http_status = null) {
        $key = $this->settings['SPOTIFY_KEY'];
        $ch = InitSpotifyCurlWithHeader('basic', $key);
        if ($ch === false) return false;

        curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200 || $result === false) {
            return false;
        }

        $result = json_decode($result, true);
        $containsValues = isset($result['access_token'], $result['expires_in']);
        if ($result === null || !$containsValues) {
            return false;
        }

        $token = $result['access_token'];
        $creation = date('Y-m-d H:i:s');
        $duration = $result['expires_in'];

        // Update token creation & duration
        $this->settings['SPOTIFY_TOKEN'] = $token;
        $this->settings['SPOTIFY_TOKEN_CREATION'] = $creation;
        $this->settings['SPOTIFY_TOKEN_DURATION'] = $duration;
        $this->SaveSettings();

        $this->token = $token;
        return $token;
    }

    /**
     * @param string $search
     * @param 'album'|'artist'|'playlist'|'track'|'show'|'episode'|'audiobook' $type
     * @param int $limit Limit of result
     * @param int $offset
     * @param int $http_status Status of HTTP request
     * @return mixed|false URL of artist's spotify page or false if request failed
     */
    function Search($search, $type = 'artist', $limit = 10, $offset = 0, &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        $limitMax = 50;
        $options = array(
            'q' => $search,
            'type' => $type,
            'offset' => $offset,
            'limit' => min($limit, $limitMax)
        );
        $url = 'https://api.spotify.com/v1/search?' . http_build_query($options);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status === 401) {
            // Bad token or token expired: refresh token and retry
            if ($this->IsTokenExpired() && $this->RefreshToken() !== false) {
                return $this->Search($search, $type, $limit, $offset, $http_status);
            }
            return false;
        }
        else if ($http_status !== 200) {
            // [403] Bad OAuth request (wrong consumer key, bad nonce, expired timestamp...)
            // [429] The app has exceeded its rate limits
            // [Other] 4xx ?
            return false;
        }

        if ($result === false) {
            return false;
        }

        $searchResult = json_decode($result, true);
        if ($searchResult === null || !checkDict($searchResult, "{$type}s", 'items')) {
            return false;
        }

        $searchResult = $searchResult["{$type}s"]['items'];
        if ($limit > $limitMax && count($searchResult) === $limitMax) {
            $next = $this->Search($search, $type, $limit - $limitMax, $offset + $limitMax);
            if ($next === false) return [];
            array_push($searchResult, ...$next);
        }

        return $searchResult;
    }

    function GetArtistByID($artistID, &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/artists/$artistID");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200 || $result === false) {
            return false;
        }

        $result = json_decode($result, true);
        if ($result === null || !isset($result['id'])) {
            return false;
        }

        return $result;
    }

    function GetTrack($trackID, &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/tracks/$trackID");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200 || $result === false) {
            return false;
        }

        $result = json_decode($result, true);
        if ($result === null || !isset($result['id'])) {
            return false;
        }

        return $result;
    }

    function GetAudioAnalysis($trackID, &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/audio-analysis/$trackID");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200 || $result === false) {
            return false;
        }

        $result = json_decode($result, true);
        if ($result === null || !isset($result['meta'])) {
            return false;
        }

        return $result;
    }

    function GetArtistAlbums($artistID, $offset = 0, &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/artists/$artistID/albums?offset=$offset");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200 || $result === false) {
            return false;
        }

        $result = json_decode($result, true);
        return $result;
    }

    function GetAlbumTracks($albumIDs, &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        $options = array('ids' => join(',', $albumIDs));
        curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/albums?' . http_build_query($options));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200 || $result === false) {
            return false;
        }

        $result = json_decode($result, true);
        if ($result === null || !isset($result['albums'])) {
            return false;
        }

        return $result['albums'];
    }

    /**
     * @param string[] $IDs
     * @param int $http_status Status of HTTP request
     * @return mixed|false URL of artist's spotify page or false if request failed
     */
    function GetAudioFeature($IDs, &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        $options = array('ids' => join(',', $IDs));
        curl_setopt($ch, CURLOPT_URL, 'https://api.spotify.com/v1/audio-features?' . http_build_query($options));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status === 401) {
            // Bad token or token expired
            if ($this->IsTokenExpired() && $this->RefreshToken() !== false) {
                return $this->GetAudioFeature($IDs, $http_status);
            }
            return false;
        }
        else if ($http_status !== 200 || $result === false) {
            return false;
        }

        $audio_features = json_decode($result, true);
        if ($audio_features === null || !checkDict($audio_features, 'audio_features'))
            return false;

        return $audio_features['audio_features'];
    }

    /**
     * @param string $artist
     * @param string $title
     * @return string|false ID of track or false if not found
     */
    function GetTrackIdByName($artist, $title) {
        $search = $this->Search("$title $artist", 'track', 1, 0, $http_status);
        if ($search === false || count($search) === 0) return false;
        return $search[0]['id'];
    }

    /**
     * @param string $artist
     * @param string $title
     * @return array|false Audio features of track or false if not found
     */
    function GetArtistTopTracks($artistID, $country = 'US', &$http_status = null) {
        if ($this->token === false) return false;

        $ch = InitSpotifyCurlWithHeader('bearer', $this->token);
        if ($ch === false) return false;

        curl_setopt($ch, CURLOPT_URL, "https://api.spotify.com/v1/artists/$artistID/top-tracks?country=$country");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status !== 200 || $result === false) {
            return false;
        }

        $result = json_decode($result, true);
        if ($result === null || !isset($result['tracks'])) {
            return false;
        }

        return $result['tracks'];
    }

    /**
     * Download track from Spotify (320k bitrate)
     * @param string $id ID of track and mp3 filename
     * @param string $directory Directory to save file
     * @return boolean True if download success or file exists, else false
     */
    function DownloadTrack($id, $directory = './') {
        if (!str_ends_with($directory, '/')) $directory .= '/';

        if ($this->token === false) return false;
        if (file_exists($directory . $id . '.mp3')) return true;

        $command = "python3 -m spotdl --auth-token {$this->token} https://open.spotify.com/track/$id --output $directory{track-id} --bitrate 320k";
        $command = escapeshellcmd($command);
        exec($command, $output, $status);

        return file_exists($directory . $id . '.mp3') && $status === 0;
    }
}

?>
