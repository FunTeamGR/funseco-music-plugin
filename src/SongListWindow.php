<?php
namespace Funseco\Music;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;

/** The "Choose the next Song" window: 4 x 5 song cards, a "+" on each to jukebox it. */
class SongListWindow
{
    public const PER_PAGE = 20;

    public const ACTIONS = ['music_widget_open', 'music_list_close', 'music_drop',
        'music_page_first', 'music_page_prev5', 'music_page_prev', 'music_page_next', 'music_page_next5', 'music_page_last'];

    /** Actions of the 20 "+" buttons: cell k of the page being shown. */
    public static function addActions(): array
    {
        return array_map(fn ($k) => 'music_add_'.$k, range(1, self::PER_PAGE));
    }

    public static function pages(Music $music): int
    {
        return max(1, (int) ceil(count(Playlist::entries($music)) / self::PER_PAGE));
    }

    public static function page(Player $player): int
    {
        return (int) state()->get('music:window:'.$player->login, 0);
    }

    /** Show (page > 0) the window, clamping the page. */
    public static function show(Player $player, int $page = 1): void
    {
        $page = max(1, min(self::pages(app(Music::class)), $page));
        state()->forever('music:window:'.$player->login, $page);
        ManiaLink::displayLogin($player, self::xml($player, $page));
    }

    public static function close(Player $player): void
    {
        state()->forget('music:window:'.$player->login);
        ManiaLink::displayLogin($player, '<manialink id="'.ManiaLink::getAction('music_window').'"></manialink>');
    }

    /** The song in cell $k (1-based) of the page the player has open. */
    public static function songAt(Player $player, int $k): ?string
    {
        $page = self::page($player);

        return $page ? (Playlist::entries(app(Music::class))[($page - 1) * self::PER_PAGE + $k - 1]['file'] ?? null) : null;
    }

    public static function xml(Player $player, int $page): string
    {
        $music = app(Music::class);
        $entries = Playlist::entries($music);
        $pages = max(1, (int) ceil(count($entries) / self::PER_PAGE));
        $count = count($entries);
        $jukebox = $music->jukebox();
        $current = Playlist::current($music)['file'];
        $e = fn (string $text) => htmlspecialchars(clean_utf8($text));
        $plus = config('music.widget.images.plus');
        $minus = config('music.widget.images.minus');
        $icon = fn (?string $image, string $style, string $sub) => $image ? 'image="'.$e($image).'"' : 'style="'.$style.'" substyle="'.$sub.'"';

        $xml = '<manialink id="'.ManiaLink::getAction('music_window').'"><frame posn="-40 31 0">'
            .'<quad posn="0 0 0" sizen="80 62" style="Bgs1" substyle="BgWindow3"/>'
            .'<quad posn="1 -1 0.01" sizen="78 3.2" style="BgsPlayerCard" substyle="BgPlayerName"/>'
            .'<quad posn="1.4 -1.1 0.02" sizen="3 3" style="Icons64x64_1" substyle="Music"/>'
            .'<label posn="5 -2 0.02" sizen="68 2" textsize="2" text="'.$e('Choose the next Song   |   Page '.$page.'/'.$pages.'   |   '.number_format($count).' '.($count === 1 ? 'Song' : 'Songs')).'"/>'
            .'<quad posn="75.4 -1.2 0.03" sizen="2.8 2.8" style="Icons64x64_1" substyle="Close" action="'.ManiaLink::getAction('music_list_close').'"/>'
            .'<frame posn="2.5 -5.7 1">';

        $slice = array_slice($entries, ($page - 1) * self::PER_PAGE, self::PER_PAGE, true);
        $cell = 0;
        foreach (array_values($slice) as $n => $entry) {
            $position = ($page - 1) * self::PER_PAGE + $n + 1;
            $cell = $n + 1;
            [$col, $row] = [intdiv($n, 5), $n % 5];
            $queued = in_array($entry['file'], $jukebox, true);
            $mine = ($jukebox[$player->login] ?? null) === $entry['file'];
            $isCurrent = $current !== '' && strcasecmp($current, $entry['file']) === 0;
            $title = $e($queued ? Text::stripColors($entry['title']) : '$z'.$entry['title']);
            $artist = $e('by '.($queued ? Text::stripColors($entry['artist']) : '$z'.$entry['artist']));

            $xml .= '<frame posn="'.($col * 19.05).' -'.($row * 9.45).' 1">'
                .'<quad posn="0 0 0.02" sizen="17.75 9.2" style="BgsPlayerCard" substyle="BgRacePlayerName"/>';
            if ($mine) {
                $xml .= '<quad posn="14.15 -5.65 0.03" sizen="4 4" '.$icon($minus, 'Icons64x64_1', 'Close').' action="'.ManiaLink::getAction('music_drop').'"/>';
            } elseif (!$queued) {
                $xml .= '<quad posn="14.15 -5.65 0.03" sizen="4 4" '.$icon($plus, 'Icons64x64_1', 'Add').' action="'.ManiaLink::getAction('music_add_'.$cell).'"/>';
            }
            $xml .= '<format textsize="1" textcolor="'.($queued || $isCurrent ? 'FFF8' : 'FFFF').'"/>'
                .'<quad posn="0.4 -0.36 0.03" sizen="16.95 2" style="'.($queued ? 'Bgs1InRace' : 'BgsPlayerCard').'" substyle="'.($queued ? 'BgListLine' : ($isCurrent ? 'BgRacePlayerName' : 'ProgressBar')).'"/>'
                .'<label posn="3.2 -0.55 0.04" sizen="17.3 0" textsize="1"'.($queued ? ' textcolor="000F"' : '').' text="Song #'.$position.'"/>'
                .'<quad posn="0.6 0 0.04" sizen="2.5 2.5" style="Icons64x64_1" substyle="Music"/>'
                .'<label posn="1 -2.7 0.04" sizen="15.85 2" scale="1" text="'.$title.'"/>'
                .'<label posn="1 -4.5 0.04" sizen="17.15 2" scale="0.9" text="'.$artist.'"/>';
            if ($isCurrent) {
                $xml .= '<quad posn="15.3 0.4 0.06" sizen="3.4 3.4" style="BgRaceScore2" substyle="Fame"/>';
            }
            $xml .= '</frame>';
        }
        $xml .= '</frame>';

        // paging and "drop my request"
        $xml .= '<frame posn="52.2 -53.2 0">';
        if (isset($jukebox[$player->login])) {
            $xml .= '<frame posn="-16.5 0 0">'
                .'<quad posn="0 0 0.12" sizen="3.2 3.2" style="Icons64x64_1" substyle="ArrowDown" action="'.ManiaLink::getAction('music_drop').'"/>'
                .'<quad posn="0.85 -1.1 0.13" sizen="1.5 1.4" bgcolor="EEEF"/>'
                .'<quad posn="0.25 -0.2 0.14" sizen="2.9 2.9" style="Icons64x64_1" substyle="Music"/>'
                .'<label posn="0.8 -0.76 0.16" sizen="6 6" textsize="2" scale="0.8" text="$S$O$F00X"/></frame>';
        }
        $button = fn (float $x, string $sub, ?string $action) => $action
            ? '<quad posn="'.$x.' 0 0.12" sizen="3.2 3.2" style="Icons64x64_1" substyle="'.$sub.'" action="'.ManiaLink::getAction($action).'"/>'
            : '<quad posn="'.$x.' 0 0.12" sizen="3.2 3.2" style="Icons64x64_1" substyle="StarGold"/>';
        $back = $page > 1;
        $forward = $page < $pages;
        $xml .= $button(6.6, 'ArrowFirst', $back ? 'music_page_first' : null)
            .$button(9.9, 'ArrowFastPrev', $back ? 'music_page_prev5' : null)
            .$button(13.2, 'ArrowPrev', $back ? 'music_page_prev' : null)
            .$button(16.5, 'ArrowNext', $forward ? 'music_page_next' : null)
            .$button(19.8, 'ArrowFastNext', $forward ? 'music_page_next5' : null)
            .$button(23.1, 'ArrowLast', $forward ? 'music_page_last' : null);

        return $xml.'</frame></frame></manialink>';
    }
}
