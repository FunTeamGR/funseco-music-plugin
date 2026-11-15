<?php
namespace Funseco\Music\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Music\SongListWindow;

/** /emusic: the "Choose the next Song" window. Only registered while the music widget is enabled. */
class EmusicCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'emusic';
    protected string $description = 'Lists musics currently on the server (see: /eyepiece)';

    public function handle(Player $player, array $args): void
    {
        SongListWindow::show($player, 1);
    }
}
