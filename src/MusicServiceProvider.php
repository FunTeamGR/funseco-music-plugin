<?php
namespace Funseco\Music;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\ControllerShutdownRequested;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\MenuIndicators;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Funseco\Music\Commands\EmusicCommand;
use Funseco\Music\Commands\MusicCommand;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;

class MusicServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/music.php', 'music');
        $this->publishPluginConfig(__DIR__.'/..');

        // clickable_rows, widget.enabled and widget.width are read in boot() (actions, /emusic, widget size): restart.
        // The switches are read where used, but a switch changed with /music wins until a reload.
        $this->app->make(SettingsRegistry::class)->declare('music', 'music', [
            Setting::bool('override_track', 'Forced song replaces track music'),
            Setting::bool('auto_nextsong', 'Change song at every map end'),
            Setting::bool('auto_shuffle', 'Shuffle the song list'),
            Setting::bool('allow_jukebox', 'Players may jukebox songs'),
            Setting::bool('strip_subdirs', 'Hide song directory names'),
            Setting::bool('strip_exts', 'Hide song extensions'),
            Setting::bool('cache_tags', 'Read song tags of .ogg files'),
            Setting::bool('cache_readonly', 'Never write the tags cache'),
            Setting::int('converter_timeout', 'Converter timeout', min: 1, max: 3600, unit: 's'),
            Setting::bool('delete_originals', 'Delete originals after converting'),
            Setting::int('clickable_rows', 'Clickable song rows', min: 1, max: 5000, restart: true),
            Setting::bool('widget.enabled', 'Current song widget', restart: true),
            Setting::string('widget.title', 'Song widget title'),
            Setting::float('widget.width', 'Song widget width', min: 15.5, max: 60, restart: true),
            Setting::bool('widget.sorting', 'Sort song list by artist'),
            Setting::bool('widget.force_songlist', 'Reopen song list after a click'),
            Setting::int('widget.max_songs', 'Songs shown in the list', min: 1, max: 5000),
        ]);
        $this->app->singleton(Music::class);
        $this->app->singletonIf(Converter::class, ProcessConverter::class);
    }

    public function boot(): void
    {
        $maniaLinks = app(ManiaLinkService::class);
        $maniaLinks->addActions(Actions::songRows(), Actions::class);
        $maniaLinks->addActions([...SongListWindow::ACTIONS, ...SongListWindow::addActions()], Actions::class);
        $maniaLinks->addActions(['music_widget', 'music_window']);

        \Chat::register(MusicCommand::class);
        if (SongWidget::enabled()) {
            \Chat::register(EmusicCommand::class);
        }
        SongWidget::register(app(WidgetRegistry::class));

        // The menu's status lights
        $indicators = app(MenuIndicators::class);
        foreach (array_keys(Music::SWITCHES) as $switch) {
            $indicators->register('music.'.$switch, fn (Player $p) => app(Music::class)->switch($switch));
        }

        // Start-up: build the list (the first song change happens at the first map end).
        Event::listen(StartupEvent::class, function () {
            app(Music::class)->load();
            SongWidget::showToAll();
        });
        // The forced song must not outlive the controller (the dedicated server keeps playing it).
        Event::listen(ControllerShutdownRequested::class, function () {
            app(Music::class)->clearForced();
        });
        Event::listen(MapStarted::class, function () {
            SongWidget::showToAll();
        });
        Event::listen(MapEnded::class, function (MapEnded $e) {
            app(Music::class)->mapEnded($e->restart);
            SongWidget::hideFromAll();
        });
        Event::listen(PlayerConnected::class, function (PlayerConnected $e) {
            SongWidget::showTo($e->player);
        });
        Event::listen(PlayerDisconnected::class, function (PlayerDisconnected $e) {
            state()->forget('music:window:'.$e->player->login);
        });
    }
}
