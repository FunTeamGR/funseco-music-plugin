<?php
namespace Funseco\Music;

/**
 * The song list as the widget and its window show it: artist and title from the tags, else
 * guessed from the file name ("Artist_-_Title.ogg"), optionally sorted by artist then title.
 */
class Playlist
{
    /** @return list<array{file: string, artist: string, title: string}> */
    public static function entries(Music $music): array
    {
        $entries = [];
        foreach (array_slice($music->songs(), 0, (int) config('music.widget.max_songs', 1900)) as $file) {
            $tags = $music->tagsOf($file);
            [$artist, $title] = $tags && $tags['artist'] !== '' ? [$tags['artist'], $tags['title']] : self::guess($file);
            $entries[] = ['file' => $file, 'artist' => $artist, 'title' => $title];
        }

        if (config('music.widget.sorting', true)) {
            usort($entries, fn ($a, $b) => [mb_strtolower($a['artist']), mb_strtolower($a['title'])] <=> [mb_strtolower($b['artist']), mb_strtolower($b['title'])]);
        }

        return $entries;
    }

    /** @return array{0: string, 1: string} artist, title */
    public static function guess(string $file): array
    {
        $name = preg_replace('/\.(ogg|mux)$/i', '', str_replace('_', ' ', preg_replace('#^.*[/\\\\]#', '', $file)));
        $pieces = array_map('trim', explode('-', $name));

        if (count($pieces) > 2) {
            [$artist, $title] = [$pieces[0], end($pieces)];
        } else {
            [$artist, $title] = [$pieces[0] !== '' ? $pieces[0] : '---', $pieces[1] ?? '(Without Ogg Vorbis Infotag)'];
        }

        return [mb_convert_case($artist, MB_CASE_TITLE), mb_convert_case($title, MB_CASE_TITLE)];
    }

    /** Artist and title of the song the server is told to play; "In-game music" by "nadeo" when none. */
    public static function current(Music $music): array
    {
        $forced = $music->forced();
        foreach (self::entries($music) as $entry) {
            if ($forced !== '' && strcasecmp($entry['file'], $forced) === 0) {
                return $entry;
            }
        }

        return ['file' => '', 'artist' => 'nadeo', 'title' => 'In-game music'];
    }
}
