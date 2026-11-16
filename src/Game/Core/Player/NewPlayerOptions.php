<?php

namespace App\Game\Core\Player;

/**
 * Everything PlayerRegistration needs to create a player; only account, universe and user name are required,
 * the rest has named defaults (null = derive from the universe / client).
 *
 * Position: galaxy, system and planet are given all together or not at all (then the universe's planet position
 * strategy chooses). stb, colorSettings and missionSettings are the id of another universe whose settings of the same
 * account are copied, or "default"/null for the defaults.
 */
final readonly class NewPlayerOptions
{
    /**
     * @param int $accountId account the player belongs to
     * @param int $universe universe the player is created in
     * @param string $userName
     * @param string|null $userLanguage default: universe language
     * @param int|null $galaxy fixed start position (all three or none)
     * @param int|null $system
     * @param int|null $planet
     * @param string|null $planetName home planet name, default: translated main planet name
     * @param int $authlevel
     * @param string|null $userIpAddress registration IP, default: client IP
     * @param string|null $stb source universe id for stb settings, or "default"
     * @param string|null $colorSettings source universe id for colour settings, or "default"
     * @param string|null $missionSettings source universe id for mission priorities, or "default"
     * @param string|null $timezone default: universe timezone
     * @param string|null $imageTheme default: universe default theme
     * @param string|null $cssTheme default: universe default theme
     */
    public function __construct(
        public int $accountId,
        public int $universe,
        public string $userName,
        public ?string $userLanguage = null,
        public ?int $galaxy = null,
        public ?int $system = null,
        public ?int $planet = null,
        public ?string $planetName = null,
        public int $authlevel = 0,
        public ?string $userIpAddress = null,
        public ?string $stb = null,
        public ?string $colorSettings = null,
        public ?string $missionSettings = null,
        public ?string $timezone = null,
        public ?string $imageTheme = null,
        public ?string $cssTheme = null,
    ) {}
}
