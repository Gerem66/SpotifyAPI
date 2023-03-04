# SpotifyAPI

## Configuration
* Create a configuration file named `config.json` with content:
```json
{
    "SPOTIFY_KEY": "",
    "SPOTIFY_TOKEN": "",
    "SPOTIFY_TOKEN_CREATION": "",
    "SPOTIFY_TOKEN_DURATION": ""
}
```
Spotify client ID and client secret can be obtained from [Spotify Developer Dashboard](https://developer.spotify.com/dashboard/applications).
And spotify key is base64 encoded string of client ID and secret, which can be obtained by running `echo -n "client_id:client_secret" | base64` in terminal.
