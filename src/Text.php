<?php
namespace Funseco\Music;

use Funseco\Core\Models\Player;

final class Text
{
    public static function get(string $key, string|int ...$values): string
    {
        $text = config("music.messages.$key", '');
        $text = format_colors($text);   // colour tokens first: values must not expand {#..}
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = (string) $value;
        }

        return strtr($text, $map);
    }

    public static function tell(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
    }

    public static function broadcast(string $message): void
    {
        \Server::query()->ChatSendServerMessage($message);
    }

    /** Make text safe to put in a chat line: a literal $ is written $$. */
    public static function literal(string $text): string
    {
        return str_replace('$', '$$', $text);
    }

    public static function stripColors(?string $text): string
    {
        $text = str_replace('$$', "\0", (string) $text);
        $text = preg_replace('/\$(?:[0-9a-f][^$]{0,2}|[lh]\[[^\]]*\]|[^$]?)/i', '', $text);

        return str_replace("\0", '$', $text);
    }
}
