<?php
namespace Funseco\Music\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Auth\Permissions;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Music\Music;
use Funseco\Music\Text;

/**
 * /music <sub> [arg]: the song list, the song jukebox and (chat_musicadmin) the server music
 * controls. Open to everyone; the admin subcommands check the ability themselves.
 */
class MusicCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'music';
    protected string $description = 'Handles server music (see: /music help)';
    protected string $usage = '<help|list|current|jukebox|drop|##|...>';

    private const ADMIN = ['reload', 'next', 'sort', 'shuffle', 'override', 'autonext', 'autoshuffle', 'allowjb', 'stripdirs', 'stripexts', 'off'];
    private const LABELS = [
        'override'    => 'Music override',
        'autonext'    => 'Music autonext',
        'autoshuffle' => 'Music autoshuffle',
        'allowjb'     => 'Allow music jukebox',
        'stripdirs'   => 'Strip subdirs',
        'stripexts'   => 'Strip extensions',
    ];

    public function handle(Player $player, array $args): void
    {
        $music = app(Music::class);
        $sub = strtolower((string) ($args[0] ?? ''));
        $arg = $args[1] ?? null;

        if ($sub === '') {
            $this->unknown($player, $args);
        } elseif (in_array($sub, self::ADMIN, true)) {
            if (!$player->allowed('chat_musicadmin')) {
                Text::tell($player, Text::get('no_admin'));
                return;
            }
            $this->admin($player, $music, $sub, $arg, $args);
        } elseif ($sub === 'help') {
            $this->help($player);
        } elseif ($sub === 'settings') {
            $this->settings($player, $music);
        } elseif ($sub === 'list') {
            $this->list($player, $music, $arg !== null ? (string) $arg : null);
        } elseif ($sub === 'current') {
            $this->current($player, $music);
        } elseif ($sub === 'jukebox' || $sub === 'jb') {
            $this->jukebox($player, $music);
        } elseif ($sub === 'drop') {
            $this->drop($player, $music);
        } elseif (ctype_digit($sub)) {
            self::request($player, (int) $sub);
        } else {
            $this->unknown($player, $args);
        }
    }

    /** /music <ID> and a click on a song row. */
    public static function request(Player $player, int $id): void
    {
        $music = app(Music::class);
        $key = $music->request($player, $id);
        if ($key === 'jukebox') {
            Text::broadcast(Text::get('jukebox', Text::stripColors($player->nickname), Text::stripColors($music->displayName($music->find($id)))));
        } else {
            Text::tell($player, Text::get($key));
        }
    }

    /** Drop the player's request, telling everybody (or the player when there is none). */
    public static function dropRequest(Player $player): void
    {
        $music = app(Music::class);
        if ($file = $music->drop($player)) {
            Text::broadcast(Text::get('jukebox_drop', Text::stripColors($player->nickname), Text::stripColors($music->displayName($file))));
        } else {
            Text::tell($player, Text::get('jukebox_nodrop'));
        }
    }

    private function drop(Player $player, Music $music): void
    {
        self::dropRequest($player);
    }

    private function unknown(Player $player, array $args): void
    {
        Text::tell($player, Text::get('unknown', implode(' ', $args)));
    }

    private function help(Player $player): void
    {
        $rows = [
            ['...', '{#black}help', 'Displays this help information'],
            ['...', '{#black}settings', 'Displays current music settings'],
            ['...', '{#black}list', 'Displays all available songs'],
            ['...', '{#black}list <xxx>', 'Searches song names/tags for <xxx>'],
            ['...', '{#black}current', 'Shows the current song'],
        ];
        if ($player->allowed('chat_musicadmin')) {
            array_push($rows,
                ['...', '{#black}reload', 'Rebuilds the song list'],
                ['...', '{#black}next', 'Skips to next song (upon next track)'],
                ['...', '{#black}sort', 'Sorts the song list'],
                ['...', '{#black}shuffle', 'Randomizes the song list'],
                ['...', '{#black}override', 'Changes track override setting'],
                ['...', '{#black}autonext', 'Changes automatic next song setting'],
                ['...', '{#black}autoshuffle', 'Changes automatic shuffle setting'],
                ['...', '{#black}allowjb', 'Changes allow jukebox setting'],
                ['...', '{#black}stripdirs', 'Changes strip subdirs setting'],
                ['...', '{#black}stripexts', 'Changes strip extensions setting'],
                ['...', '{#black}off', 'Disables music, auto next & jukebox'],
            );
        }
        array_push($rows,
            ['...', '{#black}jukebox/jb', 'Displays upcoming songs in jukebox'],
            ['...', '{#black}drop', 'Drops your currently added song'],
            ['...', '{#black}##', 'Adds a song to jukebox where ## is'],
            ['', '', 'the song Id from {#black}/music list'],
        );

        Window::show($player, '{#black}/music$g handles server music:', $rows, [0.9, 0.05, 0.2, 0.65], ['Icons64x64_1', 'TrackInfo', -0.01]);
    }

    private function settings(Player $player, Music $music): void
    {
        $yes = fn (bool $on) => $on ? 'True' : 'False';
        $rows = [];
        if ($player->allowed('chat_musicadmin')) {
            $rows[] = ['Server', '{#black}'.config('music.music_server')];
        }

        $forced = $music->forced();
        $rows[] = ['Current', '{#black}'.($forced !== '' ? $music->displayName($forced) : 'In-game music')];
        if ($forced !== '' && ($tags = $music->tagsOf($forced))) {
            $rows[] = ['', $tags['title'].'{#black} by $g'.$tags['artist']];
        }
        array_push($rows,
            ['Override', '{#black}'.$yes($music->switch('override'))],
            ['AutoNext', '{#black}'.$yes($music->switch('autonext'))],
            ['AutoShuffle', '{#black}'.$yes($music->switch('autoshuffle'))],
            ['AllowJB', '{#black}'.$yes($music->switch('allowjb'))],
            ['StripDirs', '{#black}'.$yes($music->switch('stripdirs'))],
            ['StripExts', '{#black}'.$yes($music->switch('stripexts'))],
            ['CacheTags', '{#black}'.$yes((bool) config('music.cache_tags'))],
            ['CacheRead', '{#black}'.$yes((bool) config('music.cache_readonly'))],
            ['CacheFile', '{#black}'.config('music.cache_file')],
        );

        Window::show($player, 'Music server settings:', $rows, [1.0, 0.23, 0.77], ['Icons64x64_1', 'Sound'], count($rows), card: true);
    }

    private function list(Player $player, Music $music, ?string $search): void
    {
        $tagged = (bool) config('music.cache_tags');
        $clickable = (int) config('music.clickable_rows', 1900);
        $rows = [];
        $needle = $search !== null ? mb_strtolower($search) : null;

        foreach ($music->songs() as $at => $file) {
            $tags = $music->tagsOf($file);
            if ($needle !== null && !str_contains(mb_strtolower($file), $needle)
                && !($tags && (str_contains(mb_strtolower($tags['title']), $needle) || str_contains(mb_strtolower($tags['artist']), $needle)))) {
                continue;
            }
            $id = $at + 1;
            $name = '{#black}'.$music->displayName($file);
            $rows[] = [sprintf('%02d.', $id), $id <= $clickable ? [$name, 'music_song_'.$id] : $name];
            if ($tagged) {
                $rows[] = $tags ? ['', $tags['title'].'{#black} by $g'.$tags['artist']] : [];
            }
        }

        if (!$rows) {
            Text::tell($player, Text::get('no_songs'));
            return;
        }

        Window::show($player, 'Songs On This Server:', $rows, [1.0, 0.1, 0.9], ['Icons64x64_1', 'Music'], $tagged ? 16 : 15, columns: ['Id', 'Filename']);
    }

    private function current(Player $player, Music $music): void
    {
        $forced = $music->forced();
        if ($forced === '') {
            $text = 'In-game music';
        } else {
            $text = $music->displayName($forced);
            if ($tags = $music->tagsOf($forced)) {
                // tag text is the song author's: no $-styling of ours, a literal $ stays one
                $text .= '{#orange_dark} : {#highlite}'.Text::literal(Text::stripColors($tags['title'])).'{#orange_dark} by {#highlite}'.Text::literal(Text::stripColors($tags['artist']));
            }
        }
        Text::tell($player, Text::get('current', $text));
    }

    private function jukebox(Player $player, Music $music): void
    {
        if (!$jukebox = $music->jukebox()) {
            Text::tell($player, Text::get('jukebox_empty'));
            return;
        }
        $rows = [];
        foreach ($jukebox as $file) {
            $rows[] = [sprintf('%02d.', $music->idOf($file) ?? 0), '{#black}'.$music->displayName($file)];
        }

        Window::show($player, 'Upcoming songs in the jukebox:', $rows, [0.9, 0.1, 0.8], ['Icons64x64_1', 'Music'], 15, columns: ['Id', 'Filename']);
    }

    // ---- admin --------------------------------------------------------------------------

    private function admin(Player $player, Music $music, string $sub, mixed $arg, array $args): void
    {
        $title = app(Permissions::class)->title($player);
        $nick = str_ireplace('$w', '', $player->nickname ?: $player->login);

        if (isset(self::LABELS[$sub])) {
            $value = strtolower((string) $arg);
            if ($arg === null) {
                $this->unknown($player, $args);
            } elseif ($value === 'on' || $value === 'off') {
                $music->setSwitch($sub, $value === 'on');
                Text::tell($player, Text::get('switch', self::LABELS[$sub], $value === 'on' ? 'Enabled' : 'Disabled'));
            }
            return;
        }

        switch ($sub) {
            case 'reload':
                $music->resetSwitches();
                $result = $music->load($player, convert: true);
                Text::broadcast(Text::get('reloaded', $title, $nick));
                Text::tell($player, Text::get('scan', $result['songs'], $result['renamed'], $result['converting'], $result['ignored']));
                break;
            case 'next':
                if ($file = $music->next()) {
                    Text::broadcast(Text::get('next', $title, $nick, $music->displayName($file)));
                }
                break;
            case 'sort':
                $music->sort();
                Text::broadcast(Text::get('sorted', $title, $nick));
                break;
            case 'shuffle':
                $music->shuffle();
                Text::broadcast(Text::get('shuffled', $title, $nick));
                break;
            case 'off':
                $music->off();
                Text::broadcast(Text::get('shutdown', $title, $nick));
                break;
        }
    }
}
