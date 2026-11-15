<?php
namespace Funseco\Music;

use Funseco\Core\Models\Player;
use Funseco\Music\Commands\MusicCommand;

/** ManiaLink click handlers: song rows of /music list, the widget and its song list window. */
class Actions
{
    public static function songRows(): array
    {
        return array_map(fn ($n) => 'music_song_'.$n, range(1, max(1, (int) config('music.clickable_rows', 1900))));
    }

    public function handle(Player $player, string $action): void
    {
        $music = app(Music::class);

        if (str_starts_with($action, 'music_song_')) {
            MusicCommand::request($player, (int) substr($action, strlen('music_song_')));
            return;
        }
        if (!SongWidget::enabled()) {
            return;
        }

        $page = SongListWindow::page($player) ?: 1;
        $refresh = (bool) config('music.widget.force_songlist', true);

        if (str_starts_with($action, 'music_add_')) {
            $file = SongListWindow::songAt($player, (int) substr($action, strlen('music_add_')));
            if ($file !== null && ($id = $music->idOf($file))) {
                MusicCommand::request($player, $id);
            }
            $refresh && SongListWindow::show($player, $page);
            return;
        }

        $pages = SongListWindow::pages($music);
        match ($action) {
            'music_widget_open' => SongListWindow::show($player, 1),
            'music_list_close'  => SongListWindow::close($player),
            'music_drop'        => (function () use ($player, $refresh, $page) {
                MusicCommand::dropRequest($player);
                $refresh && SongListWindow::show($player, $page);
            })(),
            'music_page_first'  => SongListWindow::show($player, 1),
            'music_page_prev5'  => SongListWindow::show($player, $page - 5),
            'music_page_prev'   => SongListWindow::show($player, $page - 1),
            'music_page_next'   => SongListWindow::show($player, $page + 1),
            'music_page_next5'  => SongListWindow::show($player, $page + 5),
            'music_page_last'   => SongListWindow::show($player, $pages),
            default             => null,
        };
    }
}
