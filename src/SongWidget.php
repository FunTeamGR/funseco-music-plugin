<?php
namespace Funseco\Music;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\UI\Region;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

/**
 * The "Current Song" widget (eyepiece music widget). Off unless music.widget.enabled; where it sits
 * comes from the WidgetRegistry (config/manialinks.php, widget name "music").
 */
class SongWidget
{
    public const NAME = 'music';
    public const HEIGHT = 8.65;

    public static function register(WidgetRegistry $registry): void
    {
        $registry->regionWidget(self::NAME, Region::TopRight, 90, width: self::width(), height: self::HEIGHT);
    }

    public static function enabled(): bool
    {
        return (bool) config('music.widget.enabled');
    }

    public static function width(): float
    {
        $width = (float) config('music.widget.width', 15.5);

        return $width < 15.5 ? 15.5 : $width;
    }

    public static function showToAll(): void
    {
        foreach (CurrentPlayers::all() as $player) {
            self::showTo($player);
        }
    }

    public static function showTo(Player $player): void
    {
        if ($xml = self::xml()) {
            ManiaLink::displayLogin($player, $xml);
        }
    }

    public static function hideFromAll(): void
    {
        if (self::enabled()) {
            ManiaLink::displayAll(self::empty());
        }
    }

    public static function empty(): string
    {
        return '<manialink id="'.ManiaLink::getAction('music_widget').'"></manialink>';
    }

    public static function xml(): ?string
    {
        $placement = self::enabled() ? app(WidgetRegistry::class)->placement(self::NAME) : null;
        if (!$placement) {
            return null;
        }

        $song = Playlist::current(app(Music::class));
        $e = fn (string $text) => htmlspecialchars(clean_utf8($text));
        $width = self::width();
        $right = $placement->x < 0;
        $extra = $width - 15.5;
        $open = config('music.widget.images.open');

        $xml = '<manialink id="'.ManiaLink::getAction('music_widget').'"><frame posn="'.$placement->x.' '.$placement->y.' 0">'
            .'<quad posn="0 0 0.001" sizen="'.$width.' '.self::HEIGHT.'" style="Bgs1InRace" substyle="NavButton" action="'.ManiaLink::getAction('music_widget_open').'"/>';
        if ($open) {
            $xml .= '<quad posn="'.($right ? 12.2 + $extra : -0.3).' -5.35 0.05" sizen="3.5 3.5" image="'.$e($open).'"/>';
        }
        $xml .= '<quad posn="0.4 -0.36 0.002" sizen="'.($width - 0.8).' 2" style="BgsPlayerCard" substyle="BgRacePlayerName"/>'
            .'<quad posn="'.($right ? 12.5 + $extra : 0.6).' 0 0.004" sizen="2.5 2.5" style="Icons64x64_1" substyle="Music"/>'
            .'<format textsize="1" textcolor="FFFF"/>'
            .'<label posn="'.($right ? 12.4 + $extra : 3.2).' -0.55 0.004" sizen="10.2 0" halign="'.($right ? 'right' : 'left').'" textsize="1" text="'.$e((string) config('music.widget.title', 'Current Song')).'"/>'
            .'<label posn="1 -2.7 0.04" sizen="13.55 2" scale="1" text="'.$e('$z'.$song['title']).'"/>'
            .'<label posn="1 -4.5 0.04" sizen="14.85 2" scale="0.9" text="'.$e('$zby '.$song['artist']).'"/>'
            .'</frame></manialink>';

        return $xml;
    }
}
