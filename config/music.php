<?php

return [
    // The owner's musicserver.xml. Runtime switches (/music override ... ) start from these and are
    // forgotten on restart.
    'override_track' => true,    // the forced song also replaces a track's own music
    'auto_nextsong'  => true,    // change song at every map end
    'auto_shuffle'   => true,    // shuffle the list at start and on /music reload
    'allow_jukebox'  => true,    // players may jukebox songs
    'strip_subdirs'  => true,    // hide the directory part of a song name
    'strip_exts'     => true,    // hide the extension of a song name
    'cache_tags'     => true,    // read Title/Artist tags of .ogg songs
    'cache_readonly' => true,    // never write the tags cache file
    'cache_file'     => 'musictagscache.xml',  // below storage/app

    // An http(s) URL ending in '/' (songs come from 'song_files'), or a folder below the server's GameData.
    'music_server' => 'Music/',
    'song_files'   => [],

    // GameData folder of the dedicated server; derived from the tracks folder it reports when null.
    'game_data' => env('TM_GAMEDATA'),

    // Files that are not Ogg/Mux are converted only by an admin's /music reload, and only when a
    // converter is configured: command and arguments with {input} and {output} placeholders, run
    // without a shell, one file at a time, e.g. ['ffmpeg', '-y', '-i', '{input}', '-vn', '-c:a', 'libvorbis', '-b:a', '128k', '{output}'].
    'converter' => null,
    // Seconds one conversion may take.
    'converter_timeout' => 300,
    // Delete the original after a successful conversion (the owner's behaviour).
    'delete_originals' => true,

    // Rows of /music list that can be clicked (song IDs above this are not clickable).
    'clickable_rows' => 1900,

    // The "Current Song" widget and the "Choose the next Song" window (/emusic). Off, like the owner's.
    'widget' => [
        'enabled'  => false,
        'title'    => 'Current Song',
        'width'    => 15.5,
        'sorting'  => true,         // song list window sorted by artist, then title
        'force_songlist' => true,   // show the window again after a click
        'max_songs' => 1900,
        // Images are optional; without a URL the built-in ManiaLink styles are used.
        'images' => [
            'open' => null,   // small arrow on the widget
            'plus' => null,
            'minus' => null,
        ],
    ],

    'messages' => [
        'current'          => '{#server}> {#orange_dark}The current song is: {#highlite}{1}',
        'next'             => '{#server}>> {#orange_dark}{1}$z$s {#highlite}{2}$z$s{#orange_dark} loaded the next song: {#highlite}{3}',
        'reloaded'         => '{#server}>> {#orange_dark}{1}$z$s {#highlite}{2}$z$s{#orange_dark} reloaded music config and cleared jukebox!',
        'sorted'           => '{#server}>> {#orange_dark}{1}$z$s {#highlite}{2}$z$s{#orange_dark} sorted song list and cleared jukebox!',
        'shuffled'         => '{#server}>> {#orange_dark}{1}$z$s {#highlite}{2}$z$s{#orange_dark} shuffled song list and cleared jukebox!',
        'jukebox'          => '{#server}>> {#highlite}{1}{#orange_dark} jukeboxed song: {#highlite}{2}',
        'jukebox_already'  => '{#server}> {#orange_dark}You already have a song in the jukebox! Wait till it\'s been played before adding another.',
        'jukebox_dupl'     => '{#server}> {#orange_dark}This song has already been added to the jukebox, pick another one.',
        'jukebox_notfound' => '{#server}> {#orange_dark}Song_ID not found - Type {#highlite}/music list{#orange_dark} to see all songs.',
        'jukebox_drop'     => '{#server}>> {#orange_dark}Player {#highlite}{1}{#orange_dark} dropped his/her song {#highlite}{2}{#orange_dark} from jukebox!',
        'jukebox_nodrop'   => '{#server}> {#orange_dark}You don\'t have a song in the jukebox, use {#highlite}/music Song_ID{#orange_dark} to add one...',
        'jukebox_empty'    => '{#server}> {#orange_dark}No songs in the jukebox, use {#highlite}/music Song_ID{#orange_dark} to add one...',
        'no_jukebox'       => '{#server}> {#highlite}/music #{#orange_dark} is not currently enabled on this server.',
        'shutdown'         => '{#server}>> {#orange_dark}{1}$z$s {#highlite}{2}$z$s{#orange_dark} disabled server music!',
        'no_songs'         => '{#server}> {#error}No songs found, try again!',
        'unknown'          => '{#server}> {#error}Unknown music command or missing parameter: {#highlite}$i {1}',
        'no_admin'         => '{#server}> {#error}You have to be in admin list to do that!',
        'switch'           => '{#server}> {#orange_dark}{1} set to {2}',
        'scan'             => '{#server}> {#orange_dark}Music folder scanned: {#highlite}{1}{#orange_dark} song(s), {2} renamed, {3} converted, {4} ignored',
        'renamed'          => '$ff0[$f00musicserver$ff0]$fff Renaming song $39f{1}$fff to $39f{2}',
        'converting'       => '$ff0[$f00musicserver$ff0]$fff Converting song $39f{1}$fff to $39f{2}',
        'convert_failed'   => '$ff0[$f00musicserver$ff0]$fff Cannot convert song $39f{1}$fff. Ignoring..',
        'no_converter'     => '$ff0[$f00musicserver$ff0]$fff Cannot convert song $39f{1}$fff: no converter configured. Ignoring..',
    ],
];
