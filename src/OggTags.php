<?php
namespace Funseco\Music;

/** Reads the Title and Artist comments of an Ogg Vorbis file (no external tools). */
final class OggTags
{
    private const READ_LIMIT = 262144;

    /** @return array{title: string, artist: string}|null both tags, or null when either is missing */
    public static function read(string $path): ?array
    {
        $data = @file_get_contents($path, false, null, 0, self::READ_LIMIT);

        return $data === false ? null : self::parse($data);
    }

    /** @return array{title: string, artist: string}|null */
    public static function parse(string $data): ?array
    {
        // Join the payload of the Ogg pages; the comment header is the packet starting "\x03vorbis".
        $stream = '';
        $pos = 0;
        $length = strlen($data);
        while ($pos + 27 <= $length && substr($data, $pos, 4) === 'OggS') {
            $segments = ord($data[$pos + 26]);
            if ($pos + 27 + $segments > $length) {
                break;
            }
            $size = array_sum(array_map('ord', str_split(substr($data, $pos + 27, $segments))));
            $stream .= substr($data, $pos + 27 + $segments, $size);
            $pos += 27 + $segments + $size;
        }

        $start = strpos($stream, "\x03vorbis");
        if ($start === false) {
            return null;
        }

        $p = $start + 7;
        $vendor = self::int32($stream, $p);
        if ($vendor === null) {
            return null;
        }
        $p += 4 + $vendor;
        $count = self::int32($stream, $p);
        if ($count === null) {
            return null;
        }
        $p += 4;

        $tags = [];
        for ($i = 0; $i < $count && $i < 1000; $i++) {
            $len = self::int32($stream, $p);
            if ($len === null || $p + 4 + $len > strlen($stream)) {
                break;
            }
            $comment = substr($stream, $p + 4, $len);
            $p += 4 + $len;
            if (($eq = strpos($comment, '=')) !== false) {
                $tags[strtoupper(substr($comment, 0, $eq))] = substr($comment, $eq + 1);
            }
        }

        if (($tags['TITLE'] ?? '') === '' || ($tags['ARTIST'] ?? '') === '') {
            return null;
        }

        return ['title' => $tags['TITLE'], 'artist' => $tags['ARTIST']];
    }

    private static function int32(string $data, int $at): ?int
    {
        return $at + 4 <= strlen($data) ? unpack('V', substr($data, $at, 4))[1] : null;
    }
}
