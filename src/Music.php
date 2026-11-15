<?php
namespace Funseco\Music;

use Illuminate\Support\Facades\Log;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\ServerInfo;

/**
 * Server music (XAseco plugin.musicserver): the ordered song list, the tags, the song jukebox,
 * the runtime switches and the forced song the dedicated server plays on the next map.
 * Everything lives in the state cache and is rebuilt from the config at every start.
 *
 * A song's ID is its 1-based position in the list; IDs move when the list is sorted, shuffled
 * or a jukeboxed song is played. The jukebox therefore stores the song file, not the ID.
 */
class Music
{
    public const SWITCHES = [
        'override'    => 'override_track',
        'autonext'    => 'auto_nextsong',
        'autoshuffle' => 'auto_shuffle',
        'allowjb'     => 'allow_jukebox',
        'stripdirs'   => 'strip_subdirs',
        'stripexts'   => 'strip_exts',
    ];

    /** Audio types worth handing to the converter; anything else in the folder is ignored. */
    private const CONVERTIBLE = ['mp3', 'wav', 'flac', 'm4a', 'aac', 'wma', 'opus', 'aif', 'aiff', 'mp2'];

    // ---- state --------------------------------------------------------------------------

    /** @return list<string> song files in list order */
    public function songs(): array
    {
        return state()->get('music:songs', []);
    }

    public function setSongs(array $songs): void
    {
        state()->forever('music:songs', array_values($songs));
    }

    private function index(): int
    {
        return (int) state()->get('music:index', 0);
    }

    private function setIndex(int $index): void
    {
        state()->forever('music:index', $index);
    }

    /** @return array<string, array{title: string, artist: string}> */
    public function tags(): array
    {
        return state()->get('music:tags', []);
    }

    public function tagsOf(string $file): ?array
    {
        return $this->tags()[$file] ?? null;
    }

    /** @return array<string, string> requester login => song file, oldest first */
    public function jukebox(): array
    {
        return state()->get('music:jukebox', []);
    }

    private function setJukebox(array $jukebox): void
    {
        state()->forever('music:jukebox', $jukebox);
    }

    public function clearJukebox(): void
    {
        $this->setJukebox([]);
    }

    /** The song last set as the server's forced music ('' = none). */
    public function forced(): string
    {
        return (string) state()->get('music:forced', '');
    }

    public function switch(string $name): bool
    {
        $stored = state()->get('music:switches', []);

        return array_key_exists($name, $stored) ? $stored[$name] : (bool) config('music.'.self::SWITCHES[$name], false);
    }

    public function setSwitch(string $name, bool $on): void
    {
        $stored = state()->get('music:switches', []);
        $stored[$name] = $on;
        state()->forever('music:switches', $stored);
    }

    /** Back to the configured switch values (admin reload). */
    public function resetSwitches(): void
    {
        state()->forget('music:switches');
    }

    // ---- songs --------------------------------------------------------------------------

    public function find(int $id): ?string
    {
        return $this->songs()[$id - 1] ?? null;
    }

    public function idOf(string $file): ?int
    {
        $at = array_search($file, $this->songs(), true);

        return $at === false ? null : $at + 1;
    }

    /** File name for display: without folders and extension as the switches say. */
    public function displayName(string $file): string
    {
        if ($this->switch('stripdirs')) {
            $file = preg_replace('#^.*[/\\\\]#', '', $file);
        }
        if ($this->switch('stripexts')) {
            $file = preg_replace('/\.[^.]*$/', '', $file);
        }

        return $file;
    }

    public function sort(): void
    {
        $songs = $this->songs();
        sort($songs, SORT_STRING);
        $this->setSongs($songs);
        $this->clearJukebox();
    }

    public function shuffle(): void
    {
        $songs = $this->songs();
        shuffle($songs);
        $this->setSongs($songs);
        $this->clearJukebox();
    }

    // ---- jukebox ------------------------------------------------------------------------

    /**
     * Jukebox the song with this ID for the player.
     *
     * @return string message key: 'jukebox' (queued), 'no_jukebox', 'jukebox_notfound', 'jukebox_already' or 'jukebox_dupl'
     */
    public function request(Player $player, int $id): string
    {
        if (!$this->switch('allowjb')) {
            return 'no_jukebox';
        }
        if ($id < 1 || !($file = $this->find($id))) {
            return 'jukebox_notfound';
        }
        $jukebox = $this->jukebox();
        if (isset($jukebox[$player->login])) {
            return 'jukebox_already';
        }
        if (in_array($file, $jukebox, true)) {
            return 'jukebox_dupl';
        }
        $jukebox[$player->login] = $file;
        $this->setJukebox($jukebox);

        return 'jukebox';
    }

    /** Remove the player's request; the song it was for, or null. */
    public function drop(Player $player): ?string
    {
        $jukebox = $this->jukebox();
        if (!isset($jukebox[$player->login])) {
            return null;
        }
        $file = $jukebox[$player->login];
        unset($jukebox[$player->login]);
        $this->setJukebox($jukebox);

        return $file;
    }

    // ---- playback -----------------------------------------------------------------------

    public function gameData(): ?string
    {
        $dir = config('music.game_data') ?: (($tracks = ServerInfo::getTracksDirectory()) ? dirname($tracks) : null);

        return $dir ? rtrim($dir, '/\\') : null;
    }

    public function isUrl(): bool
    {
        return (bool) preg_match('#^https?://#i', (string) config('music.music_server'));
    }

    private function serverPath(): string
    {
        return rtrim((string) config('music.music_server'), '/').'/';
    }

    /** Folder of the songs on disk (null for an http server or when GameData is unknown). */
    public function folder(): ?string
    {
        $gameData = $this->gameData();

        return !$this->isUrl() && $gameData ? $gameData.'/'.$this->serverPath() : null;
    }

    public function reachable(string $file): bool
    {
        if ($this->isUrl()) {
            return true; // not probed: the dedicated server fetches it
        }

        return ($folder = $this->folder()) !== null && is_file($folder.$file);
    }

    private function setForced(string $file): void
    {
        \Server::query()->SetForcedMusic($this->switch('override'), $file === '' ? '' : $this->serverPath().$file);
        state()->forever('music:forced', $file);
    }

    /** Make this song the server's forced music if its file can be reached. */
    public function play(string $file): bool
    {
        if (!$this->reachable($file)) {
            Log::warning("[Music] Song $file cannot be reached; not set as server music");

            return false;
        }
        $this->setForced($file);

        return true;
    }

    public function clearForced(): void
    {
        $this->setForced('');
    }

    /** Admin /music next: load the next song in the list now and skip the change at the map end. */
    public function next(): ?string
    {
        $songs = $this->songs();
        if (!$songs) {
            return null;
        }
        $index = ($this->index() + 1) % count($songs);
        if (!$this->play($songs[$index])) {
            return null;
        }
        $this->setIndex($index);
        state()->forever('music:manual_next', true);

        return $songs[$index];
    }

    /** Admin /music off. */
    public function off(): void
    {
        $this->clearForced();
        $this->setSwitch('autonext', false);
        $this->setSwitch('allowjb', false);
        $this->clearJukebox();
    }

    /** Every map end except a restart: jukeboxed song, else the next song, else no forced music. */
    public function mapEnded(bool $restart): void
    {
        if ($restart) {
            return;
        }
        if (state()->pull('music:manual_next')) {
            return;
        }

        $jukebox = $this->jukebox();
        if ($jukebox) {
            $login = array_key_first($jukebox);
            $file = $jukebox[$login];
            unset($jukebox[$login]);
            $this->setJukebox($jukebox);
            $this->moveAfterCurrent($file);
            if ($this->play($file)) {
                return;
            }
        }

        if ($this->switch('autonext')) {
            $songs = $this->songs();
            if ($songs) {
                $this->setIndex(($this->index() + 1) % count($songs));
                $this->play($songs[$this->index()]);
            }

            return;
        }

        $this->clearForced();
    }

    /** The requested song becomes the song right after the current one and then the current one. */
    private function moveAfterCurrent(string $file): void
    {
        $songs = $this->songs();
        $current = $songs[$this->index()] ?? null;
        if ($current === $file || ($from = array_search($file, $songs, true)) === false) {
            return;
        }
        array_splice($songs, $from, 1);
        $at = $current !== null && ($c = array_search($current, $songs, true)) !== false ? $c + 1 : 0;
        array_splice($songs, $at, 0, [$file]);
        $this->setSongs($songs);
        $this->setIndex($at);
    }

    // ---- building the list --------------------------------------------------------------

    /**
     * Rebuild the list (start-up, /music reload). Songs in the folder are renamed to safe names;
     * other audio files are converted, one at a time on the event loop, only when $convert is set
     * and a converter is configured. $admin hears about renames and conversions; without one
     * they are only logged.
     *
     * @return array{songs: int, renamed: int, converting: int, ignored: int}
     */
    public function load(?Player $admin = null, bool $convert = false, ?Converter $converter = null): array
    {
        $files = [];
        $renamed = [];
        $pending = [];
        $ignored = 0;

        if ($this->isUrl()) {
            $files = array_values(array_filter((array) config('music.song_files'), 'is_string'));
        } elseif (($folder = $this->folder()) && is_dir($folder)) {
            foreach ((array) scandir($folder) as $entry) {
                if ($entry === '.' || $entry === '..' || is_dir($folder.$entry) || !str_contains($entry, '.')) {
                    continue;
                }
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                $base = pathinfo($entry, PATHINFO_FILENAME);
                $clean = preg_replace('/\s/', '_', preg_replace('/[^a-zA-Z0-9\-_\s.]/', '', $base));
                if ($clean === '') {
                    $ignored++;
                    continue;
                }

                if ($ext === 'ogg' || $ext === 'mux') {
                    $name = $clean.'.'.$ext;
                    if ($name !== $entry) {
                        if (file_exists($folder.$name) || !@rename($folder.$entry, $folder.$name)) {
                            $ignored++;
                            Log::warning("[Music] Cannot rename $entry to $name");
                            continue;
                        }
                        $renamed[] = [$entry, $name];
                    }
                    $files[] = $name;
                } elseif ($convert && in_array($ext, self::CONVERTIBLE, true) && !is_file($folder.$clean.'.ogg')) {
                    $pending[] = [$entry, $clean.'.ogg'];
                } else {
                    $ignored++;
                }
            }
        }

        $files = array_values(array_unique($files));
        if ($this->switch('autoshuffle')) {
            shuffle($files);
        }
        $this->setSongs($files);
        $this->setIndex(0);
        $this->clearJukebox();
        state()->forget('music:manual_next');
        $this->loadTags($files);

        foreach ($renamed as [$from, $to]) {
            $this->say($admin, Text::get('renamed', Text::literal($from), Text::literal($to)));
        }

        $converter ??= app(Converter::class);
        $converting = 0;
        if ($pending) {
            if ($converter->available()) {
                $converting = count($pending);
                $this->convertAll($pending, $admin, $converter);
            } else {
                foreach ($pending as [$from]) {
                    $this->say($admin, Text::get('no_converter', Text::literal($from)));
                    $ignored++;
                }
            }
        }

        Log::info('[Music] '.count($files).' song(s) listed, '.count($renamed).' renamed, '.$converting.' converting, '.$ignored.' ignored');

        return ['songs' => count($files), 'renamed' => count($renamed), 'converting' => $converting, 'ignored' => $ignored];
    }

    private function say(?Player $admin, string $message): void
    {
        $admin ? Text::tell($admin, $message) : Log::info('[Music] '.preg_replace('/\$[0-9a-f]{3}|\$./i', '', $message));
    }

    /** @param list<array{0: string, 1: string}> $pending */
    private function convertAll(array $pending, ?Player $admin, Converter $converter): void
    {
        $item = array_shift($pending);
        if ($item === null) {
            return;
        }
        [$from, $to] = $item;
        $folder = $this->folder();
        $this->say($admin, Text::get('converting', Text::literal($from), Text::literal($to)));

        $converter->convert($folder.$from, $folder.$to)->then(function ($ok) use ($from, $to, $folder, $admin, $pending, $converter) {
            if ($ok) {
                if (config('music.delete_originals', true)) {
                    @unlink($folder.$from);
                }
                $songs = $this->songs();
                if (!in_array($to, $songs, true)) {
                    $songs[] = $to;
                    $this->setSongs($songs);
                    $this->loadTags([$to]);
                }
            } else {
                $this->say($admin, Text::get('convert_failed', Text::literal($from)));
            }
            $this->convertAll($pending, $admin, $converter);
        });
    }

    // ---- tags ---------------------------------------------------------------------------

    /** Read the tags cache and the tags of the given .ogg songs not yet known. */
    private function loadTags(array $files): void
    {
        if (!config('music.cache_tags')) {
            return;
        }

        $cacheFile = storage_path('app/'.config('music.cache_file', 'musictagscache.xml'));
        $map = $this->tags() + $this->readCache($cacheFile);
        $folder = $this->folder();
        $added = false;
        foreach ($files as $file) {
            if (isset($map[$file]) || !$folder || !preg_match('/\.ogg$/i', $file)) {
                continue;
            }
            if ($tags = OggTags::read($folder.$file)) {
                $map[$file] = $tags;
                $added = true;
            }
        }
        state()->forever('music:tags', $map);

        if ($added && !config('music.cache_readonly')) {
            $this->writeCache($cacheFile, $map);
        }
    }

    private function readCache(string $path): array
    {
        $xml = is_file($path) ? @simplexml_load_file($path) : false;
        $map = [];
        foreach ($xml ? $xml->song : [] as $song) {
            $map[(string) $song->file] = ['title' => (string) $song->title, 'artist' => (string) $song->artist];
        }

        return $map;
    }

    private function writeCache(string $path, array $map): void
    {
        $xml = new \SimpleXMLElement('<tags/>');
        foreach ($map as $file => $tags) {
            $song = $xml->addChild('song');
            $song->addChild('file', htmlspecialchars((string) $file));
            $song->addChild('title', htmlspecialchars($tags['title']));
            $song->addChild('artist', htmlspecialchars($tags['artist']));
        }
        @file_put_contents($path, $xml->asXML());
    }
}
