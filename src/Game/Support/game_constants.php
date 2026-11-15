<?php

/**
 * Fixed game constants (module ids, element ids, fleet states, limits, ...), compile-time `const`
 * declarations loaded by Composer (autoload.files). Request-dependent values (URLs, timestamp, version)
 * are defined by App\Runtime\GameBootstrap::defineRequestConstants().
 */

const DEFAULT_THEME = 'br0game';
const DISCORD_URL = 'https://discord.gg/jhYYN3yuat';

const COMBAT_ENGINE = 'xnova';

// For Fatal Errors!
const DEFAULT_LANG = 'de';

// SUPPORT WILDCAST DOMAINS
const UNIS_WILDCAST = false;

// FIELDS FOR EACH LEVEL OF THE LUNAR BASE
const FIELDS_BY_MOONBASIS_LEVEL = 3;

// FIELDS FOR EACH LEVEL OF THE TERRAFORMER
const FIELDS_BY_TERRAFORMER = 5.5;

// TIME IN SECONDS for 24 hours
const ONE_DAY = 86400;

// TIME IN SECONDS, TO (i) APPEAR ON GALAXY
const INACTIVE = 604800;

// TIME IN SECONDS, TO (i I) APPEAR ON GALAXY
const INACTIVE_LONG = 2419200;

// FEE FOR CANCEL QUEUE IN SHIPYARD
const FACTOR_CANCEL_SHIPYARD = 0.6;

// MINIMUM FLEET TIME
const MIN_FLEET_TIME = 5;

// PHALANX COST'S
const PHALANX_DEUTERIUM = 5000;

// Time of changable nick after changing nick.
const USERNAME_CHANGETIME = 604800;

// Max Results in Searchpage (-1 = disable)
const SEARCH_LIMIT = 25;

// Messages per page at message list
const MESSAGES_PER_PAGE = 10;

// banned users per page at ban list
const BANNED_USERS_PER_PAGE = 25;

// How much IP Block ll be checked
// 1 = (AAA); 2 = (AAA.BBB); 3 = (AAA.BBB.CCC)
const COMPARE_IP_BLOCKS = 2;

// Max Round on Combats
const MAX_ATTACK_ROUNDS = 6;

// Enable the one-Click SImulation on Spy-Raports
const ENABLE_SIMULATOR_LINK = true;

// Max. User Session in Seconds
const SESSION_LIFETIME = 43200;

// ENABLE Mutlialert on sending fleets
const ENABLE_MULTIALERT = false;

// UTF-8 support for names (required for non-english chars!)
const UTF8_SUPPORT = true;

// Prevent the use of one account on multiple devices
const PREVENT_MULTISESSIONS = false;

// Define, how its more hard to spy all inforation
/*
    if [Spy tech level of sender] > [Spy tech level of target]
        min amount of spies = -1 * (abs([Spy tech level of sender] - [Spy tech level of target])
            * SPY_DIFFENCE_FACTOR) ^ 2;
    else
        min amount of spies = -1 * (abs([Spy tech level of sender] - [Spy tech level of target])
            * SPY_DIFFENCE_FACTOR) ^ 2;

*/
const SPY_DIFFENCE_FACTOR = 1;

// Define, how its more hard to spy all inforation
/*
    min amount of spies = see MissionCaseSpy.php#78

    To see Fleet        = {min amount of spies}
    To see Defense      = {min amount of spies} + 1 * SPY_VIEW_FACTOR
    To see Buildings    = {min amount of spies} + 3 * SPY_VIEW_FACTOR
    To see Technology   = {min amount of spies} + 5 * SPY_VIEW_FACTOR
*/
const SPY_VIEW_FACTOR = 1;

// Bash Settings
const BASH_TIME = 86400;

// Bash rule on wars:
// 0 = NORMAL
// 1 = ON WAR, BASH RULE IS DEACTIVE
const BASH_WAR = 1;

// MINIMUM FLEET TIME MUST HIGHER THEN BASH_TIME
const FLEETLOG_AGE = 86400;

// Root IDs
const ROOT_UNI = 1;
const ROOT_USER = 1;

// AUTHLEVEL
const AUTH_ADM = 3;
const AUTH_OPS = 2;
const AUTH_MOD = 1;
const AUTH_USR = 0;

// Planet types
const TYPE_PLANET = '1';
const TYPE_DEBRIS = '2'; //unused
const TYPE_MOON = '3';

// Modules
const MODULE_ALLIANCE = 0;
const MODULE_MISSION_ATTACK = 1;
const MODULE_BUILDING = 2;
const MODULE_RESEARCH = 3;
const MODULE_SHIPYARD_FLEET = 4;
const MODULE_SHIPYARD_DEFENSIVE = 5;
const MODULE_BUDDYLIST = 6;
const MODULE_FLEET_TABLE = 9;
const MODULE_FLEET_EVENTS = 10;
const MODULE_GALAXY = 11;
const MODULE_BATTLEHALL = 12;
const MODULE_INFORMATION = 14;
const MODULE_IMPERIUM = 15;
const MODULE_MESSAGES = 16;
const MODULE_NOTICE = 17;
const MODULE_PHALANX = 19;
const MODULE_PLAYERCARD = 20;
const MODULE_BANLIST = 21;
const MODULE_RECORDS = 22;
const MODULE_RESSOURCE_LIST = 23;
const MODULE_MISSION_SPY = 24;
const MODULE_STATISTICS = 25;
const MODULE_SEARCH = 26;
const MODULE_SUPPORT = 27;
const MODULE_TECHTREE = 28;
const MODULE_MISSION_DESTROY = 29;
const MODULE_MISSION_EXPEDITION = 30;
const MODULE_MISSION_RECYCLE = 32;
const MODULE_MISSION_HOLD = 33;
const MODULE_MISSION_TRANSPORT = 34;
const MODULE_MISSION_COLONY = 35;
const MODULE_MISSION_STATION = 36;
const MODULE_FLEET_TRADER = 38;
const MODULE_SIMULATOR = 39;
const MODULE_MISSILEATTACK = 40;
const MODULE_SHORTCUTS = 41;
const MODULE_MISSION_ACS = 42;
const MODULE_MISSION_TRADE = 43;
const MODULE_MISSION_TRANSFER = 44;
const MODULE_MARKET_TRADE = 45;              // Trade Ressources to other players through the marketplace
const MODULE_MARKET_TRANSFER = 46;           // Transfer Ships to other players through the marketplace
const MODULE_SPYTECH_DEPENDENT_STATS = 47;   // Viewable stats depent on spytech level
const MODULE_REPAIR_DOCK = 48;               // Repair ships in orbit
const MODULE_RESOURCE_STASH = 49;            // Save ressources from stealing
const MODULE_VMODE_KICK = 50;                // Kick player from vmode if he is inactive ( Big I default 28 days)
const MODULE_EMPTY_BUDDY = 51;               // Empty Buddylist and remove from Alliance on inactive outside of vmode
const MODULE_STATS_JSON = 52;                // Create stats.json file for external use (contains player stats per Universe)
const MODULE_TUTORIAL = 53;
const MODULE_TUTORIAL_REWARDS = 54;
const MODULE_HONORPOINTS = 55;               // Honorpoints for players
const MODULE_PIRATE_BASES = 56;              // Pirate Bases Cronjob and related features

// module id => name map: GameBootstrap::registerModules()

// FLEET STATE
const FLEET_OUTWARD = 0;
const FLEET_RETURN = 1;
const FLEET_HOLD = 2;
const FLEET_DESTROYED = 3;

// ELEMENT FLAGS
const ELEMENT_BUILD = 1; # ID 0 - 99
const ELEMENT_TECH = 2; # ID 101 - 199
const ELEMENT_FLEET = 4; # ID 201 - 399
const ELEMENT_DEFENSIVE = 8; # ID 401 - 599
const ELEMENT_OFFICIER = 16; # ID 601 - 699
const ELEMENT_BONUS = 32; # ID 701 - 799
const ELEMENT_RACE = 64; # ID 801 - 899
const ELEMENT_PLANET_RESOURCE = 128; # ID 901 - 949
const ELEMENT_USER_RESOURCE = 256; # ID 951 - 999

// .. 512, 1024, 2048, 4096, 8192, 16384, 32768

const ELEMENT_PRODUCTION = 65536;
const ELEMENT_STORAGE = 131072;
const ELEMENT_ONEPERPLANET = 262144;
const ELEMENT_BOUNS = 524288;
const ELEMENT_BUILD_ON_PLANET = 1048576;
const ELEMENT_BUILD_ON_MOONS = 2097152;
const ELEMENT_RESOURCE_ON_TF = 4194304;
const ELEMENT_RESOURCE_ON_FLEET = 8388608;
const ELEMENT_RESOURCE_ON_STEAL = 16777216;

// Resources

const RESOURCE_METAL = 901;
const RESOURCE_CRYSTAL = 902;
const RESOURCE_DEUT = 903;
const RESOURCE_ENERGY = 911;

// buildings
const metal_mine = 1;
const crystal_mine = 2;
const deuterium_sintetizer = 3;
const solar_plant = 4;
const university = 6;
const fusion_plant = 12;
const ROBOT_FACTORY = 14;
const NANITE_FACTORY = 15;
const SHIPYARD = 21;
const METAL_STORE = 22;
const CRYSTAL_STORE = 23;
const DEUTERIUM_STORE = 24;
const RESEARCH_LABORATORY = 31;
const TERRAFORMER = 33;
const REPAIR_DOCK = 35;
const MOONBASE = 41;
const PHALANX = 42;
const SPRUNGTOR = 43;
const SILO = 44;

// shiptypes
const SHIP_TYPE_UNKNOWN = 'unknown';
const SHIP_TYPE_RECYCLER = 'recycler';

// ships
const SHIP_SMALL_CARGO = 202;
const SHIP_LARGE_CARGO = 203;
const SHIP_LIGHT_FIGHTER = 204;
const SHIP_HEAVY_FIGHTER = 205;
const SHIP_CRUISER = 206;
const SHIP_BATTLESHIP = 207;
const SHIP_COLOSHIP = 208;
const SHIP_RECYCLER = 209;
const SHIP_PROBE = 210;
const SHIP_BOMBER = 211;
const SHIP_SOLSAT = 212;
const SHIP_DESTROYER = 213;
const SHIP_RIP = 214;
const SHIP_BATTLECRUISER = 215;
const LUNE_NOIR = 216;
const EV_TRANSPORTER = 217;
const STAR_CRASHER = 218;
const BATTLE_RECYCLER = 219;
const STEEL_COLLOSSUS = 220;
const STORM_BLADE = 221;
const HEAVY_CRUISER = 222;
const SHILD_DRONE = 223;
const IMPROVISED_ASSAULT_TRANSPORTER = 224;
const STORM_TRANSPORTER = 225;
const COLOSSAL_BATTLE_TRANSPORTER = 226;
const GIGA_RECYKLER = 226;
const OPTIMIZED_RECYCLER = 227;
const OMEGA_RECYCLER = 228;
const VIPER = 229;
const INTERCEPTOR = 230;
const BEHEMOTH = 231;
const VOID_TITAN = 232;

// Defense
const MISSILE_LAUNCHER = 401;
const LIGHT_LASER_TURRET = 402;
const HEAVY_LASER_TURRET = 403;
const GAUSS_CANNON = 404;
const ION_CANNON = 405;
const PLASMA_CANNON = 406;
const SMALL_SHIELD_DOME = 407;
const LARGE_SHIELD_DOME = 408;
const PLANET_PROTECTOR = 409;
const GRAVITON_CANON = 410;
const ORBITAL_STATION = 411;
const BUNKER = 412;
const TITAN_LASER = 413;
const DECOY = 414;

// MISSILES
const INTERCEPTOR_MISSILE = 502;
const INTERPLANETARY_MISSILE = 503;

// Missions
const MISSION_ATTACK = 1;
const MISSION_ACS = 2;
const MISSION_TRANSPORT = 3;
const MISSION_STATION = 4;
const MISSION_HOLD = 5;
const MISSION_SPY = 6;
const MISSION_COLONISATION = 7;
const MISSION_RECYCLING = 8;
const MISSION_DESTRUCTION = 9;
const MISSION_MISSILE = 10;
const MISSION_EXPEDITION = 15;
const MISSION_TRADE = 16;
const MISSION_TRANSFER = 17;
const MISSION_PIRATEBASE_ATTACK = 20;
const MISSION_PIRATEBASE_SPY = 21;
const MISSION_PIRATEBASE_RECYCLING = 22;

// general time constants
const TIME_30_MIN = 1800;
const TIME_1_HOUR = 3600;
const TIME_6_HOURS = 21600;
const TIME_12_HOURS = 43200;
const TIME_24_HOURS = 86400;
const TIME_72_HOURS = 259200;
const TIME_1_WEEK = 604800;
const TIME_1_MONTH = 2592000;

// universe status
const STATUS_OPEN = 0;
const STATUS_CLOSED = 1;
const STATUS_REG_ONLY = 2;
const STATUS_LOGIN_ONLY = 3;

// universe type
const UNIVERSE_LINEAR = 0;
const UNIVERSE_CIRCULAR = 1;
const UNIVERSE_BALL = 2;

// galaxy type
const GALAXY_LINEAR = 0;
const GALAXY_CIRCULAR = 1;
const GALAXY_BALL = 2;

// technologies
const SPY_TECH = 106;
const COMPUTER_TECH = 108;
const MILITARY_TECH = 109;
const SHIELD_TECH = 110;
const DEFENCE_TECH = 111;
const ENERGY_TECH = 113;
const HYPERSPACE_TECH = 114;
const COMBUSTION_TECH = 115;
const IMPULSE_MOTOR_TECH = 117;
const HYPERSPACE_MOTOR_TECH = 118;
const LASER_TECH = 120;
const IONIC_TECH = 121;
const BUSTER_TECH = 122;
const INTERGALACTIC_TECH = 123;
const EXPEDITION_TECH = 124;
const METAL_PROC_TECH = 131;
const CRYSTAL_PROC_TECH = 132;
const DEUTERIUM_PROC_TECH = 133;
const FUEL_EFFICIENCY_TECH = 134;
const GRAVITON_TECH = 199;

//achievements
const NOT_IMPLEMENTED_ACHIEVEMENTS = [
    1,
    2,
    11,
    13,
    15,
    18,
    29,
    30,
    41,
    42,
    44,
    46,
    50,
    53,
    54
];

//battle
const REAL_FIGHT = 1;
const SIM_FIGHT = 2;
const EXPO_FIGHT = 3;
const PIRATE_BASE_FIGHT = 4;

//battle result
const ATTACKER = "a";
const DEFENDER = "r";
const DRAW = "w";

const MAXPLANETPICTURECOUNT = 58;
const TOPKB_LIMIT = 150;

const MESSAGE_TYPE_SPY_REPORT = 0;
const MESSAGE_TYPE_SPY_DEFENCE = 7;
const MESSAGE_TYPE_USER = 1;
const MESSAGE_TYPE_ALLIANCE = 2;
const MESSAGE_TYPE_BATTLE_REPORT = 3;
const MESSAGE_TYPE_SYSTEM = 4;
const MESSAGE_TYPE_TRANSPORT = 5;
const MESSAGE_TYPE_FOREIGN_TRANSPORT = 6;
const MESSAGE_TYPE_EXPEDITION = 15;
const MESSAGE_TYPE_GAME = 50;
const MESSAGE_TYPE_BUILD_LIST = 99;
const MESSAGE_TYPE_ALL = 100;
const MESSAGE_TYPE_FAVORITES = 998;
const MESSAGE_TYPE_SEARCH = 999;

const STATS_PLAYERS = 1;
const STATS_ALLIANCES = 2;

const STATS_TYPE_POINTS = 1;
const STATS_TYPE_FLEET = 2;
const STATS_TYPE_RESEARCH = 3;
const STATS_TYPE_BUILDINGS = 4;
const STATS_TYPE_DEFENSES = 5;
const STATS_TYPE_HONOR = 6;
const STATS_TYPE_UNITS = 7;

const DIPLO_MAIN = 0;
const DIPLO_WING = 1;
const DIPLO_ALLIANCE_PACT = 2;
const DIPLO_TRADE_PACT = 3;
const DIPLO_NON_AGRESSION_PACT = 4;
const DIPLO_WAR = 5;
const DIPLO_SECRET_ALLIANCE_PACT = 6;
const DIPLO_PEACE = 7;
