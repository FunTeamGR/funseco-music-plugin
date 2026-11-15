<?php
namespace Funseco\Music\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Funseco\Core\Testing\FakesServer;
use Funseco\Music\Tests\TestCase;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\MenuIndicators;
use Funseco\Music\Converter;
use Funseco\Music\Music;
use Funseco\Music\OggTags;
use Funseco\Music\Playlist;
use Funseco\Music\SongListWindow;
use Funseco\Music\SongWidget;

class MusicTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private string $gameData;
    private Player $alice;
    private Player $bob;
    private Player $admin;
    private Player $op;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        CurrentPlayers::clear();

        $this->gameData = sys_get_temp_dir().'/music-test-'.bin2hex(random_bytes(4));
        mkdir($this->gameData.'/Music', 0777, true);
        config(['music.game_data' => $this->gameData, 'music.auto_shuffle' => false, 'music.cache_tags' => true,
            'music.cache_file' => 'music-test-cache-'.basename($this->gameData).'.xml']);
        config(['adminops.masteradmins' => ['boss'], 'adminops.admins' => ['adm'], 'adminops.operators' => ['op']]);

        $make = function (string $login, string $nick): Player {
            $player = Player::factory()->create(['login' => $login, 'nickname' => $nick]);
            CurrentPlayers::add($player);

            return $player;
        };
        $this->alice = $make('alice', 'Alice');
        $this->bob = $make('bob', 'Bob');
        $this->admin = $make('adm', 'Adm');
        $this->op = $make('op', 'Op');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->gameData.'/Music/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->gameData.'/Music');
        @rmdir($this->gameData);
        @unlink(storage_path('app/'.config('music.cache_file')));
        parent::tearDown();
    }

    private function songs(array $names = ['a_one.ogg', 'b_two.ogg', 'c_three.ogg']): Music
    {
        foreach ($names as $name) {
            file_put_contents($this->gameData.'/Music/'.$name, 'x');
        }
        $music = app(Music::class);
        $music->load();
        $this->calls = [];

        return $music;
    }

    private function toLogin(string $login): array
    {
        return array_values(array_map(fn ($a) => $a[0], array_filter($this->sent('ChatSendServerMessageToLogin'), fn ($a) => $a[1] === $login)));
    }

    /** A minimal Ogg Vorbis file: identification page, then the comment page. */
    public static function ogg(string $title, string $artist): string
    {
        $page = function (string $packet, int $seq): string {
            $lacing = str_repeat("\xff", intdiv(strlen($packet), 255)).chr(strlen($packet) % 255);

            return 'OggS'."\0".chr($seq === 0 ? 2 : 0).str_repeat("\0", 8).pack('V', 1).pack('V', $seq).pack('V', 0).chr(strlen($lacing)).$lacing.$packet;
        };
        $comments = ['TITLE='.$title, 'ARTIST='.$artist];
        $comment = "\x03vorbis".pack('V', 5).'vendr'.pack('V', count($comments));
        foreach ($comments as $c) {
            $comment .= pack('V', strlen($c)).$c;
        }

        return $page("\x01vorbis".str_repeat("\0", 20), 0).$page($comment."\x01", 1);
    }

    public function test_ogg_tags_are_read_and_both_must_exist()
    {
        $this->assertSame(['title' => 'Son', 'artist' => 'Therion'], OggTags::parse(self::ogg('Son', 'Therion')));
        $this->assertNull(OggTags::parse(self::ogg('Son', '')));
        $this->assertNull(OggTags::parse('garbage'));
    }

    public function test_load_lists_the_folder_alphabetically_and_cleans_names_for_the_reloading_admin_only()
    {
        file_put_contents($this->gameData.'/Music/Zed Song (live)!.ogg', 'x');
        file_put_contents($this->gameData.'/Music/alpha.ogg', 'x');
        file_put_contents($this->gameData.'/Music/readme.txt', 'x');
        file_put_contents($this->gameData.'/Music/noext', 'x');
        mkdir($this->gameData.'/Music/sub');
        $music = app(Music::class);

        $result = $music->load();

        $this->assertSame(['Zed_Song_live.ogg', 'alpha.ogg'], $music->songs());
        $this->assertFileExists($this->gameData.'/Music/Zed_Song_live.ogg');
        $this->assertSame(1, $result['renamed']);
        $this->assertSame(1, $result['ignored']); // readme.txt
        $this->assertSame([], $this->sent('ChatSendServerMessageToLogin'), 'start-up only logs');
        $this->assertSame([], $this->sent('ChatSendServerMessage'));

        @unlink($this->gameData.'/Music/sub');
        @rmdir($this->gameData.'/Music/sub');
        @unlink($this->gameData.'/Music/noext');
        @unlink($this->gameData.'/Music/readme.txt');
    }

    public function test_reload_resets_the_runtime_switches_to_the_config()
    {
        $music = app(Music::class);
        $music->setSwitch('autonext', ! $music->switch('autonext'));
        $configured = (bool) config('music.auto_nextsong', false);
        $this->assertNotSame($configured, $music->switch('autonext'));

        Chat::resolve('/music reload', 'adm');
        $this->assertSame($configured, $music->switch('autonext'));
    }

    public function test_controller_shutdown_clears_the_forced_music()
    {
        $this->calls = [];
        \Server::shouldReceive('halt');
        event(new \Funseco\Core\SharedKernel\Events\ControllerShutdownRequested);
        $this->assertSame([[true, ""]], $this->sent("SetForcedMusic"));
    }

    public function test_shutdown_stops_the_controller_only_after_the_listeners_have_queued_their_queries()
    {
        \Server::shouldReceive('halt')->never();   // deferred: closing the socket at once would drop SetForcedMusic
        event(new \Funseco\Core\SharedKernel\Events\ControllerShutdownRequested);
        $this->assertSame([[true, ""]], $this->sent("SetForcedMusic"));
    }

    public function test_reload_tells_the_admin_what_was_renamed()
    {
        file_put_contents($this->gameData.'/Music/Bad Name.ogg', 'x');
        Chat::resolve('/music reload', 'adm');

        $this->assertSame(['Bad_Name.ogg'], app(Music::class)->songs());
        $this->assertStringContainsString('Renaming song', implode(' ', $this->toLogin('adm')));
        $this->assertStringContainsString('reloaded music config and cleared jukebox', $this->messages('ChatSendServerMessage')[0]);
        $this->assertSame([], $this->toLogin('alice'));
    }

    public function test_shuffle_on_load_keeps_all_songs()
    {
        config(['music.auto_shuffle' => true]);
        $music = $this->songs(array_map(fn ($n) => "s$n.ogg", range(1, 12)));

        $this->assertCount(12, $music->songs());
        $this->assertEqualsCanonicalizing(array_map(fn ($n) => "s$n.ogg", range(1, 12)), $music->songs());
    }

    public function test_tags_come_from_the_ogg_files_and_search_matches_names_and_tags()
    {
        file_put_contents($this->gameData.'/Music/a_one.ogg', self::ogg('Follow the Signs', 'Born of Osiris'));
        file_put_contents($this->gameData.'/Music/b_two.ogg', 'no tags');
        $music = app(Music::class);
        $music->load();
        $this->calls = [];

        $this->assertSame(['title' => 'Follow the Signs', 'artist' => 'Born of Osiris'], $music->tagsOf('a_one.ogg'));
        $this->assertNull($music->tagsOf('b_two.ogg'));

        Chat::resolve('/music list osiris', 'alice');
        $window = $this->window();
        $this->assertStringContainsString('Songs On This Server:', $window);
        $this->assertStringContainsString('01.', $window);
        $this->assertStringContainsString('a_one', $window);
        $this->assertStringNotContainsString('b_two', $window);
        $this->assertStringContainsString('Follow the Signs', $window);
        $this->assertStringContainsString('action="'.ManiaLink::getAction('music_song_1').'"', $window);

        $this->calls = [];
        Chat::resolve('/music list zzz', 'alice');
        $this->assertStringContainsString('No songs found', $this->toLogin('alice')[0]);

        Chat::resolve('/music list TWO', 'alice');
        $this->assertStringContainsString('02.', $this->window());
        $this->assertStringContainsString('action="'.ManiaLink::getAction('music_song_2').'"', $this->window());
    }

    public function test_jukebox_rules_and_messages()
    {
        $this->songs();

        Chat::resolve('/music 2', 'alice');
        $this->assertStringContainsString('Alice', $this->messages('ChatSendServerMessage')[0]);
        $this->assertStringContainsString('b_two', $this->messages('ChatSendServerMessage')[0]);
        $this->assertSame(['alice' => 'b_two.ogg'], app(Music::class)->jukebox());

        Chat::resolve('/music 3', 'alice');
        $this->assertStringContainsString("already have a song", $this->toLogin('alice')[0]);
        Chat::resolve('/music 2', 'bob');
        $this->assertStringContainsString('already been added', $this->toLogin('bob')[0]);
        Chat::resolve('/music 9', 'bob');
        Chat::resolve('/music 0', 'bob');
        $this->assertStringContainsString('Song_ID not found', $this->toLogin('bob')[1]);
        $this->assertStringContainsString('Song_ID not found', $this->toLogin('bob')[2]);

        Chat::resolve('/music jb', 'bob');
        $this->assertStringContainsString('Upcoming songs in the jukebox:', $this->window());

        $this->calls = [];
        Chat::resolve('/music drop', 'bob');
        $this->assertStringContainsString("don't have a song", $this->toLogin('bob')[0]);
        Chat::resolve('/music drop', 'alice');
        $this->assertSame([], app(Music::class)->jukebox());
        $this->assertStringContainsString('dropped his/her song', $this->messages('ChatSendServerMessage')[0]);

        Chat::resolve('/music jb', 'bob');
        $this->assertStringContainsString('No songs in the jukebox', last($this->toLogin('bob')));
    }

    public function test_song_row_click_jukeboxes_for_the_clicker_and_allowjb_off_blocks_it()
    {
        $this->songs();

        ManiaLink::dispatchAnswer($this->bob, ManiaLink::getAction('music_song_3'));
        $this->assertSame(['bob' => 'c_three.ogg'], app(Music::class)->jukebox());

        app(Music::class)->setSwitch('allowjb', false);
        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('music_song_1'));
        $this->assertStringContainsString('is not currently enabled', $this->toLogin('alice')[0]);
    }

    public function test_admin_subcommands_need_the_ability_and_unknown_ones_are_reported()
    {
        $this->songs();

        Chat::resolve('/music sort', 'alice');
        Chat::resolve('/music reload', 'op');
        $this->assertStringContainsString('admin list', $this->toLogin('alice')[0]);
        $this->assertStringContainsString('admin list', $this->toLogin('op')[0], 'operators lack chat_musicadmin');

        Chat::resolve('/music foo bar', 'alice');
        Chat::resolve('/music', 'alice');
        Chat::resolve('/music override', 'adm');
        $this->assertStringContainsString('Unknown music command or missing parameter', $this->toLogin('alice')[1]);
        $this->assertStringContainsString('foo bar', $this->toLogin('alice')[1]);
        $this->assertStringContainsString('Unknown music command', $this->toLogin('alice')[2]);
        $this->assertStringContainsString('Unknown music command', $this->toLogin('adm')[0]);
    }

    public function test_switches_sort_shuffle_and_off()
    {
        $music = $this->songs(['c.ogg', 'a.ogg', 'b.ogg']);
        Chat::resolve('/music 1', 'alice');

        Chat::resolve('/music sort', 'adm');
        $this->assertSame(['a.ogg', 'b.ogg', 'c.ogg'], $music->songs());
        $this->assertSame([], $music->jukebox());
        $this->assertStringContainsString('sorted song list', $this->messages('ChatSendServerMessage')[1]);

        Chat::resolve('/music shuffle', 'adm');
        $this->assertEqualsCanonicalizing(['a.ogg', 'b.ogg', 'c.ogg'], $music->songs());

        Chat::resolve('/music override off', 'adm');
        $this->assertFalse($music->switch('override'));
        $this->assertStringContainsString('Music override set to Disabled', last($this->toLogin('adm')));
        Chat::resolve('/music stripexts OFF', 'adm');
        $this->assertSame('a', substr($music->displayName('a.ogg'), 0, 1));
        $this->assertSame('a.ogg', $music->displayName('a.ogg'));
        Chat::resolve('/music autonext maybe', 'adm');
        $this->assertTrue($music->switch('autonext'), 'neither on nor off does nothing');

        $this->calls = [];
        Chat::resolve('/music off', 'adm');
        $this->assertFalse($music->switch('autonext'));
        $this->assertFalse($music->switch('allowjb'));
        $this->assertSame([[false, '']], $this->sent('SetForcedMusic'));
        $this->assertStringContainsString('disabled server music', $this->messages('ChatSendServerMessage')[0]);
    }

    public function test_map_end_plays_the_next_song_with_the_override_switch()
    {
        $music = $this->songs();

        $music->mapEnded(false);
        $this->assertSame([[true, 'Music/b_two.ogg']], $this->sent('SetForcedMusic'));
        $this->assertSame('b_two.ogg', $music->forced());

        $music->mapEnded(true); // a restart changes nothing
        $this->assertCount(1, $this->sent('SetForcedMusic'));

        $music->mapEnded(false);
        $music->mapEnded(false); // wraps to the first
        $this->assertSame('a_one.ogg', $music->forced());
    }

    public function test_map_end_serves_the_oldest_request_first_and_moves_it_after_the_current_song()
    {
        $music = $this->songs(['a.ogg', 'b.ogg', 'c.ogg', 'd.ogg']);
        Chat::resolve('/music 4', 'alice');
        Chat::resolve('/music 3', 'bob');

        $music->mapEnded(false);

        $this->assertSame('d.ogg', $music->forced());
        $this->assertSame(['a.ogg', 'd.ogg', 'b.ogg', 'c.ogg'], $music->songs());
        $this->assertSame(['bob' => 'c.ogg'], $music->jukebox());

        $music->mapEnded(false);
        $this->assertSame('c.ogg', $music->forced());
        $this->assertSame(['a.ogg', 'd.ogg', 'c.ogg', 'b.ogg'], $music->songs());

        $music->mapEnded(false); // jukebox empty: plain next
        $this->assertSame('b.ogg', $music->forced());
    }

    public function test_unreachable_jukeboxed_song_falls_back_to_the_next_one_and_autonext_off_clears()
    {
        $music = $this->songs();
        Chat::resolve('/music 3', 'alice');
        unlink($this->gameData.'/Music/c_three.ogg');

        $music->mapEnded(false);
        $this->assertSame('b_two.ogg', $music->forced());

        $music->setSwitch('autonext', false);
        $this->calls = [];
        $music->mapEnded(false);
        $this->assertSame([[true, '']], $this->sent('SetForcedMusic'));
        $this->assertSame('', $music->forced());
    }

    public function test_admin_next_loads_the_song_now_and_skips_the_next_map_end()
    {
        $music = $this->songs();

        Chat::resolve('/music next', 'adm');
        $this->assertSame('b_two.ogg', $music->forced());
        $this->assertStringContainsString('loaded the next song', $this->messages('ChatSendServerMessage')[0]);

        $music->mapEnded(false);
        $this->assertSame('b_two.ogg', $music->forced(), 'manual next flag consumed');
        $music->mapEnded(false);
        $this->assertSame('c_three.ogg', $music->forced());
    }

    public function test_current_strips_dollar_styling_from_the_ogg_tags()
    {
        file_put_contents($this->gameData.'/Music/a_one.ogg', self::ogg('$f00Red $oSong$$', '$wWide'));
        $music = app(Music::class);
        $music->load();
        $music->mapEnded(false);
        $this->calls = [];

        Chat::resolve('/music current', 'alice');
        $line = $this->toLogin('alice')[0];
        $this->assertStringContainsString('Red Song$$', $line);
        $this->assertStringContainsString('Wide', $line);
        $this->assertStringNotContainsString('$f00', $line);
        $this->assertStringNotContainsString('$o', $line);
    }

    public function test_current_and_settings_windows()
    {
        file_put_contents($this->gameData.'/Music/a_one.ogg', self::ogg('Follow the Signs', 'Born of Osiris'));
        $music = app(Music::class);
        $music->load();
        $this->calls = [];

        Chat::resolve('/music current', 'alice');
        $this->assertStringContainsString('In-game music', $this->toLogin('alice')[0]);

        $music->mapEnded(false);
        $this->calls = [];
        Chat::resolve('/music current', 'alice');
        $this->assertStringContainsString('a_one', $this->toLogin('alice')[0]);
        $this->assertStringContainsString('Follow the Signs', $this->toLogin('alice')[0]);

        Chat::resolve('/music settings', 'alice');
        $this->assertStringContainsString('Music server settings:', $this->window());
        $this->assertStringNotContainsString('>Server<', $this->window());
        Chat::resolve('/music settings', 'adm');
        $this->assertStringContainsString('Server', $this->window());
        $this->assertStringContainsString('Music/', $this->window());

        Chat::resolve('/music help', 'alice');
        $this->assertStringNotContainsString('autoshuffle', $this->window());
        Chat::resolve('/music help', 'adm');
        $this->assertStringContainsString('autoshuffle', $this->window());
    }

    public function test_conversion_runs_only_on_reload_through_the_configured_converter()
    {
        $log = [];
        $fake = new class($log) implements Converter {
            public array $deferreds = [];
            public function __construct(public array &$log) {}
            public function available(): bool { return true; }
            public function convert(string $input, string $output): PromiseInterface
            {
                $this->log[] = [basename($input), basename($output)];
                file_put_contents($output, 'ogg');
                $d = new Deferred();
                $this->deferreds[] = $d;

                return $d->promise();
            }
        };
        $this->app->instance(Converter::class, $fake);
        file_put_contents($this->gameData.'/Music/My Track.mp3', 'x');
        file_put_contents($this->gameData.'/Music/a.ogg', 'x');

        app(Music::class)->load(); // start-up: nothing converted
        $this->assertSame([], $log);
        $this->assertSame(['a.ogg'], app(Music::class)->songs());

        Chat::resolve('/music reload', 'adm');
        $this->assertSame([['My Track.mp3', 'My_Track.ogg']], $log);
        $this->assertStringContainsString('Converting song', implode(' ', $this->toLogin('adm')));
        $fake->deferreds[0]->resolve(true);

        $this->assertContains('My_Track.ogg', app(Music::class)->songs());
        $this->assertFileDoesNotExist($this->gameData.'/Music/My Track.mp3');
    }

    public function test_without_a_converter_other_formats_are_ignored_not_shelled_out()
    {
        file_put_contents($this->gameData.'/Music/song.mp3', 'x');

        Chat::resolve('/music reload', 'adm');

        $this->assertSame([], app(Music::class)->songs());
        $this->assertFileExists($this->gameData.'/Music/song.mp3');
        $this->assertStringContainsString('no converter configured', implode(' ', $this->toLogin('adm')));
    }

    public function test_playlist_guesses_artist_and_title_from_file_names()
    {
        $this->assertSame(['01', 'Follow The Signs'], Playlist::guess('01_-_Born_of_Osiris_-_Follow_the_Signs.ogg'));
        $this->assertSame(['Lake Of Tears', '(Without Ogg Vorbis Infotag)'], Playlist::guess('Lake_Of_Tears.ogg'));
        $this->assertSame(['Therion', 'Son Of The Staves Of Time'], Playlist::guess('Therion_-_Son_Of_The_Staves_Of_Time.OGG'));
    }

    public function test_menu_indicators_follow_the_music_switches()
    {
        $music = $this->songs();
        $lights = app(MenuIndicators::class);

        $this->assertSame(1, $lights->value('music.override', $this->alice));
        $music->setSwitch('override', false);
        $this->assertSame(0, $lights->value('music.override', $this->alice));
        $this->assertNull($lights->value('music.nope', $this->alice));
    }

    public function test_widget_and_song_list_window_are_off_by_default_and_follow_the_config()
    {
        $this->songs();
        $this->assertNull(SongWidget::xml());
        $this->assertFalse(Chat::has('emusic'));

        config(['music.widget.enabled' => true]);
        $xml = SongWidget::xml();
        $this->assertNotNull($xml);
        $this->assertStringContainsString('In-game music', $xml);
        $this->assertStringContainsString('by nadeo', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));

        app(Music::class)->mapEnded(false); // b_two.ogg
        $this->assertStringContainsString('B Two', SongWidget::xml());
        $this->assertStringContainsString('(Without Ogg Vorbis Infotag)', SongWidget::xml());
    }

    public function test_song_list_window_pages_and_plus_button_jukeboxes_the_song()
    {
        config(['music.widget.enabled' => true]);
        $names = array_map(fn ($n) => sprintf('Artist_-_Song%02d.ogg', $n), range(1, 25));
        $music = $this->songs($names);

        SongListWindow::show($this->alice, 1);
        $xml = $this->window();
        $this->assertStringContainsString('Choose the next Song   |   Page 1/2   |   25 Songs', $xml);
        $this->assertStringContainsString('Song #1', $xml);
        $this->assertStringContainsString('Song #20', $xml);
        $this->assertStringNotContainsString('Song #21', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));

        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('music_page_next'));
        $this->assertStringContainsString('Song #21', $this->window());
        $this->assertStringContainsString('Page 2/2', $this->window());

        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('music_add_2')); // 22nd song (sorted)
        $this->assertSame(['alice' => 'Artist_-_Song22.ogg'], $music->jukebox());
        $this->assertStringContainsString('Page 2/2', $this->window(), 'window is shown again on the same page');
        $this->assertStringContainsString('action="'.ManiaLink::getAction('music_drop').'"', $this->window());

        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('music_drop'));
        $this->assertSame([], $music->jukebox());

        ManiaLink::dispatchAnswer($this->alice, ManiaLink::getAction('music_list_close'));
        $this->assertStringContainsString('></manialink>', $this->window());
    }
}
