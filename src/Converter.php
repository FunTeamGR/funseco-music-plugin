<?php
namespace Funseco\Music;

use React\Promise\PromiseInterface;

/** Turns one audio file into an Ogg Vorbis file without blocking the controller. */
interface Converter
{
    /** Is a converter configured at all? */
    public function available(): bool;

    /** Resolves true when $output exists afterwards, false when the conversion failed. */
    public function convert(string $input, string $output): PromiseInterface;
}
