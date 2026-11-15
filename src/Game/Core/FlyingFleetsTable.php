<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

class FlyingFleetsTable
{
    use \App\Game\Core\GameUtilities;

    protected int $userId = 0;
    protected int $planetId = 0;
    protected bool $IsPhalanx = false;
    protected string $missions = '';

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {
    }

    public function setUser(int $userId): void
    {
        $this->userId = $userId;
    }

    public function setPlanet(int $planetId): void
    {
        $this->planetId = $planetId;
    }

    public function setPhalanxMode()
    {
        $this->IsPhalanx = true;
    }

    public function setMissions(string $missions): void
    {
        $this->missions = implode(',', array_filter(explode(',', $missions), 'is_numeric'));
    }

    private function getFleets(int $acsID = 0): array
    {
        if ($this->IsPhalanx && $acsID <= 0) {
            $where = '(fleet.`start_id` = :planetId AND fleet.`start_type` = :type AND fleet.`mission` != :stay) OR'
                . ' (fleet.`end_id` = :planetId AND fleet.`end_type` = :type AND fleet.`mission` != :rec AND fleet.`mission` != :trade AND fleet.`status` IN (:outward, :return))';

            $param = [
                ':planetId' => $this->planetId,
                ':type'     => TYPE_PLANET,
                ':stay'     => MISSION_STATION,
                ':rec'      => MISSION_RECYCLING,
                ':trade'    => MISSION_TRADE,
                ':outward'  => FLEET_OUTWARD,
                ':return'   => FLEET_RETURN,
            ];
        } elseif ($acsID > 0) {
            $where  = 'fleet.`group` = :acsId';
            $param = [':acsId'    => $acsID];
        } elseif ($this->missions) {
            $where = '(fleet.`owner` = :userId OR (fleet.`target_owner` = :userId AND fleet.`mission` != :rec AND fleet.`mission` != :trade))'
                . ' AND fleet.`mission` IN (' . $this->missions . ')';
            $param = [
                ':userId'   => $this->userId,
                ':rec'      => MISSION_RECYCLING,
                ':trade'    => MISSION_TRADE,
            ];
        } else {
            $where  = 'fleet.`owner` = :userId OR (fleet.`target_owner` = :userId AND fleet.`mission` != :rec AND fleet.`mission` != :trade)';
            $param = [
                ':userId'   => $this->userId,
                ':rec'      => MISSION_RECYCLING,
                ':trade'    => MISSION_TRADE,
            ];
        }

        $sql = 'SELECT DISTINCT fleet.*, ownuser.`username` as own_username, targetuser.`username` as target_username,
            ownplanet.`name` as own_planetname, targetplanet.`name` as target_planetname
            FROM %%FLEETS%% fleet
            LEFT JOIN %%USERS%% ownuser ON (ownuser.`id` = fleet.`owner`)
            LEFT JOIN %%USERS%% targetuser ON (targetuser.`id` = fleet.`target_owner`)
            LEFT JOIN %%PLANETS%% ownplanet ON (ownplanet.`id` = fleet.`start_id`)
            LEFT JOIN %%PLANETS%% targetplanet ON (targetplanet.`id` = fleet.`end_id`)
            WHERE ' . $where . ' ORDER BY fleet.`id`;';

        return $this->db->select($sql, $param);
    }

    public function renderTable(bool $isPhalanx = false): array
    {
        $db = $this->db;
        $fleetResult            = $this->getFleets();
        $ACSDone                = [];
        $FleetData              = [];

        $phalanxLogID = 0;
        if ($isPhalanx) {
            $USER = & $this->state->USER;
            $PLANET = & $this->state->PLANET;
            $sql = 'INSERT INTO %%PHALANX_LOG%%
                (`owner`, `owner_planet_id`, `target`, `target_planet_id`, `phalanx_time`)
                VALUES (:owner, :planet_id, :target, :designatedPlanetID, :timestamp);';

            $db->insert($sql, [
                ':owner'                => $USER['id'],
                ':planet_id'            => $PLANET['id'],
                ':target'               => $this->userId,
                ':designatedPlanetID'   => $this->planetId,
                ':timestamp'            => $this->state->runtime->timestamp,
            ]);

            $phalanxLogID = $db->lastInsertId();
        }

        foreach ($fleetResult as $fleetRow) {

            if ($isPhalanx && $phalanxLogID > 0) {
                $db->insert(
                    'INSERT INTO %%PHALANX_FLEETS%% (`phalanx_log_id`, `fleet_id`)
                    VALUES (:phalanx_log_id, :fleet_id);',
                    [
                        ':phalanx_log_id' => $phalanxLogID,
                        ':fleet_id'       => $fleetRow['id'],
                    ]
                );
            }

            if ($fleetRow['mission'] == MISSION_TRADE) {
                $fleetRow['target_planetname'] = "";
            }
            if (
                $fleetRow['status'] == FLEET_OUTWARD && $fleetRow['arrival_time'] > $this->state->runtime->timestamp
                && ($fleetRow['group'] == 0 || !isset($ACSDone[$fleetRow['group']]))
            ) {
                $ACSDone[$fleetRow['group']]      = true;
                $FleetData[$fleetRow['arrival_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    0
                );
            }

            if (
                $fleetRow['mission'] == MISSION_MISSILE
                || (($fleetRow['mission'] == MISSION_TRANSFER
                || $fleetRow['mission'] == MISSION_STATION) && $fleetRow['status'] == FLEET_OUTWARD)
            ) {
                continue;
            }

            if (
                $fleetRow['stay_time'] != $fleetRow['arrival_time']
                && $fleetRow['stay_time'] > $this->state->runtime->timestamp
                && ($this->IsPhalanx && $fleetRow['end_id'] == $this->planetId)
            ) {
                $FleetData[$fleetRow['stay_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    2
                );
            }

            $MissionsOK = 5;
            if ($fleetRow['stay_time'] > $this->state->runtime->timestamp && $fleetRow['mission'] == $MissionsOK) {
                $FleetData[$fleetRow['stay_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    2
                );
            }

            if ($fleetRow['owner'] != $this->userId) {
                continue;
            }

            if ($fleetRow['return_time'] > $this->state->runtime->timestamp) {
                $FleetData[$fleetRow['return_time'] . sprintf('%010d', $fleetRow['id'])] = $this->buildFleetEventTable(
                    $fleetRow,
                    1
                );
            }

        }

        ksort($FleetData);
        return $FleetData;
    }

    private function buildFleetEventTable(array $fleetRow, int $FleetState): array
    {
        $Time   = 0;
        $Rest   = 0;

        if (
            $FleetState == 0 && $this->IsPhalanx && $fleetRow['group'] != 0
            && (
                strpos(
                    (
                        isset($this->state->requestServer['HTTPS']) && $this->state->requestServer['HTTPS'] === 'on' ? "https" : "http"
                    ) . "://$this->state->requestServer[HTTP_HOST]$this->state->requestServer[REQUEST_URI]",
                    'page=phalanx'
                ) !== false
            )
        ) {
            // Rebuilt the code above to eliminate possible errors with ACS without Phalanx.
            $acsResult      = $this->getFleets($fleetRow['group']);
            $EventString    = '';

            foreach ($acsResult as $acsRow) {
                if ($acsRow['group'] != 0) {
                    $Return         = $this->getEventData($acsRow, $FleetState);

                    $Rest           = $Return[0];
                    $EventString    .= $Return[1] . '<br><br>';
                    $Time           = $Return[2];
                }
            }

            $EventString    = substr($EventString, 0, -8);
        } elseif ($FleetState == 0 && $fleetRow['group'] != 0) {
            $acsResult      = $this->getFleets($fleetRow['group']);
            $EventString    = '';

            foreach ($acsResult as $acsRow) {
                $Return         = $this->getEventData($acsRow, $FleetState);

                $Rest           = $Return[0];
                $EventString    .= $Return[1] . '<br><br>';
                $Time           = $Return[2];
            }

            $EventString    = substr($EventString, 0, -8);
        } else {
            list($Rest, $EventString, $Time) = $this->getEventData($fleetRow, $FleetState);
        }

        return [
            // built from translations, numbers and the escaped player name: trusted markup
            'text'          => new \Twig\Markup($EventString, 'UTF-8'),
            'returntime'    => $Time,
            'resttime'      => $Rest,
        ];
    }

    public function getEventData(array $fleetRow, int $Status): array
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $db = $this->db;
        $Owner          = $fleetRow['owner'] == $this->userId;
        $friendly = !$Owner && $fleetRow['target_owner'] != $this->userId;
        $FleetStyle  = [
            MISSION_ATTACK => 'attack',
            MISSION_ACS => 'federation',
            MISSION_TRANSPORT => 'transport',
            MISSION_STATION => 'deploy',
            MISSION_HOLD => 'hold',
            MISSION_SPY => 'espionage',
            MISSION_COLONISATION => 'colony',
            MISSION_RECYCLING => 'harvest',
            MISSION_DESTRUCTION => 'destroy',
            MISSION_MISSILE => 'missile',
            MISSION_EXPEDITION => 'transport',
            MISSION_TRADE => 'transport',
            MISSION_TRANSFER => 'transport',
            MISSION_PIRATEBASE_ATTACK => 'attack',
            MISSION_PIRATEBASE_SPY => 'espionage',
            MISSION_PIRATEBASE_RECYCLING => 'harvest',
        ];

        $GoodMissions = [MISSION_TRANSPORT, MISSION_HOLD];
        $MissionType = $fleetRow['mission'];
        if ($friendly && $fleetRow['group'] != 0) {
            $MissionType = MISSION_ACS;
        }

        $FleetPrefix = ($Owner == true) ? 'own' : '';
        if ($MissionType != MISSION_ATTACK && !$Owner && !$friendly && $fleetRow['group'] != 0) {
            $FleetPrefix = 'hostile';
        }
        $FleetType = $FleetPrefix . $FleetStyle[$MissionType];
        if (
            !$Owner
            && ($MissionType == MISSION_ATTACK || $MissionType == MISSION_ACS)
            && $Status == FLEET_OUTWARD && $fleetRow['target_owner'] != $this->userId
        ) {
            $FleetName = $LNG['cff_acs_fleet'];
        } else {
            $FleetName = $LNG['ov_fleet'];
        }
        $FleetContent = $this->createFleetPopupedFleetLink(
            $fleetRow,
            $FleetName,
            $FleetPrefix . $FleetStyle[$MissionType]
        );
        $FleetCapacity = $this->createFleetPopupedMissionLink(
            $fleetRow,
            $LNG['type_mission_' . $MissionType],
            $FleetPrefix . $FleetStyle[$MissionType]
        );
        $FleetClass = '';
        $StartType = $LNG['type_planet_' . $fleetRow['start_type']];
        $TargetType = $LNG['type_planet_' . $fleetRow['end_type']];

        // In the phalanx the table is built for the scanned player: their fleets must not read "Your ..."
        $ownText = $Owner && !$this->IsPhalanx;
        $textKey = function (string $own) use ($LNG, $ownText): string {
            $other = str_replace('cff_mission_own_', 'cff_mission_other_', $own);

            return !$ownText && isset($LNG[$other]) ? $other : $own;
        };

        if ($MissionType == MISSION_RECYCLING) {
            if ($Status == FLEET_OUTWARD) {
                $EventString = sprintf(
                    $LNG[$textKey('cff_mission_own_recy_0')],
                    $FleetContent,
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $FleetCapacity
                );
            } else {
                $EventString = sprintf(
                    $LNG[$textKey('cff_mission_own_recy_1')],
                    $FleetContent,
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    $FleetCapacity
                );
            }
        } elseif ($MissionType == MISSION_MISSILE) {
            if ($Owner) {
                $EventString = sprintf(
                    $LNG[$textKey('cff_mission_own_mip')],
                    $fleetRow['ship_amount'],
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    $TargetType,
                    $fleetRow['target_planetname'],
                    GetTargetAddressLink($fleetRow, $FleetType)
                );
            } else {
                $EventString = sprintf(
                    $LNG['cff_mission_target_mip'],
                    $fleetRow['ship_amount'],
                    $this->buildHostileFleetPlayerLink($fleetRow),
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    $TargetType,
                    $fleetRow['target_planetname'],
                    GetTargetAddressLink($fleetRow, $FleetType)
                );
            }
        } elseif ($MissionType == MISSION_EXPEDITION) {
            if ($Status == FLEET_OUTWARD) {
                $EventString = sprintf(
                    $LNG[$textKey('cff_mission_own_expo_0')],
                    $FleetContent,
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $FleetCapacity
                );
            } elseif ($Status == FLEET_HOLD) {
                $EventString = sprintf(
                    $LNG[$textKey('cff_mission_own_expo_2')],
                    $FleetContent,
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $FleetCapacity
                );
            } else {
                $EventString = sprintf(
                    $LNG[$textKey('cff_mission_own_expo_1')],
                    $FleetContent,
                    GetTargetAddressLink($fleetRow, $FleetType),
                    $StartType,
                    $fleetRow['own_planetname'],
                    GetStartAddressLink($fleetRow, $FleetType),
                    $FleetCapacity
                );
            }
        } else {
            if ($Owner == true) {
                if ($Status == FLEET_OUTWARD) {
                    $EventString  = sprintf(
                        $LNG[$textKey('cff_mission_own_0')],
                        $FleetContent,
                        $StartType,
                        $fleetRow['own_planetname'],
                        GetStartAddressLink($fleetRow, $FleetType),
                        $TargetType,
                        $fleetRow['target_planetname'],
                        GetTargetAddressLink($fleetRow, $FleetType),
                        $FleetCapacity
                    );
                } elseif ($Status == FLEET_RETURN) {
                    $EventString  = sprintf(
                        $LNG[$textKey('cff_mission_own_1')],
                        $FleetContent,
                        $TargetType,
                        $fleetRow['target_planetname'],
                        GetTargetAddressLink($fleetRow, $FleetType),
                        $StartType,
                        $fleetRow['own_planetname'],
                        GetStartAddressLink($fleetRow, $FleetType),
                        $FleetCapacity
                    );
                } else {
                    $EventString  = sprintf(
                        $LNG[$textKey('cff_mission_own_2')],
                        $FleetContent,
                        $StartType,
                        $fleetRow['own_planetname'],
                        GetStartAddressLink($fleetRow, $FleetType),
                        $TargetType,
                        $fleetRow['target_planetname'],
                        GetTargetAddressLink($fleetRow, $FleetType),
                        $FleetCapacity
                    );
                }
            } else {
                if (($MissionType == MISSION_ATTACK || $MissionType == MISSION_PIRATEBASE_ATTACK || $MissionType == MISSION_ACS) && $Status == FLEET_OUTWARD) {
                    $EventString = sprintf(
                        $LNG['cff_mission_acs'],
                        $FleetContent,
                        $StartType,
                        $fleetRow['own_planetname'],
                        GetStartAddressLink($fleetRow, $FleetType),
                        $TargetType,
                        $fleetRow['target_planetname'],
                        GetTargetAddressLink($fleetRow, $FleetType),
                        $FleetCapacity
                    );
                } else {
                    if ($Status == FLEET_HOLD) {
                        $Message    = $LNG['cff_mission_target_stay'];
                    } elseif (in_array($MissionType, $GoodMissions)) {
                        $Message    = $LNG['cff_mission_target_good'];
                    } else {
                        $Message    = $LNG['cff_mission_target_bad'];
                    }

                    $EventString = sprintf(
                        $Message,
                        $FleetContent,
                        $this->buildHostileFleetPlayerLink($fleetRow),
                        $StartType,
                        $fleetRow['own_planetname'],
                        GetStartAddressLink($fleetRow, $FleetType),
                        $TargetType,
                        $fleetRow['target_planetname'],
                        GetTargetAddressLink($fleetRow, $FleetType),
                        $FleetCapacity
                    );
                }
            }
        }
        
        if ($Status == FLEET_OUTWARD) {
            if ($MissionType == MISSION_ACS) {
                $sql = "SELECT count(uta.`acsID`) as count FROM %%USERS_TO_ACS%% AS uta 
                    INNER JOIN %%FLEETS%% AS f ON uta.`acsID` = f.`group`
                    WHERE uta.`userID` = :userId AND f.`id` = :fleetId;";
                $friend = $db->selectSingle($sql, [
                    ':userId'   => $USER['id'],
                    ':fleetId'  => $fleetRow['id']
                ], 'count');
                if ($Owner == true) {
                    $FleetClass = 'colorMission2Own';
                } elseif ($friend > 0) {
                    $FleetClass = 'colorMission2friend';
                } else {
                    $FleetClass = 'colorMission2Foreign';
                }
            } elseif ($MissionType == MISSION_ATTACK || $MissionType == MISSION_PIRATEBASE_ATTACK) {
                $FleetClass = $Owner ? 'colorMission1Own' : 'colorMission1Foreign';
            } elseif ($MissionType == MISSION_TRANSPORT) {
                $FleetClass = $Owner ? 'colorMission3Own' : 'colorMission3Foreign';
            } elseif ($MissionType == MISSION_STATION) {
                $FleetClass = $Owner ? 'colorMission4Own' : 'colorMission4Foreign';
            } elseif ($MissionType == MISSION_HOLD) {
                $FleetClass = $Owner ? 'colorMission5Own' : 'colorMission5Foreign';
            } elseif ($MissionType == MISSION_SPY || $MissionType == MISSION_PIRATEBASE_SPY) {
                $FleetClass = $Owner ? 'colorMission6Own' : 'colorMission6Foreign';
            } elseif ($MissionType == MISSION_COLONISATION) {
                $FleetClass = $Owner ? 'colorMission7Own' : 'colorMission7Foreign';
            } elseif ($MissionType == MISSION_RECYCLING || $MissionType == MISSION_PIRATEBASE_RECYCLING) {
                $FleetClass = $Owner ? 'colorMission8Own' : 'colorMission8Foreign';
            } elseif ($MissionType == MISSION_DESTRUCTION) {
                $FleetClass = $Owner ? 'colorMission9Own' : 'colorMission9Foreign';
            } elseif ($MissionType == MISSION_MISSILE) {
                $FleetClass = $Owner ? 'colorMission10Own' : 'colorMission10Foreign';
            } elseif ($MissionType == MISSION_EXPEDITION) {
                $FleetClass = $Owner ? 'colorMission15Own' : 'colorMission15Foreign';
            } elseif ($MissionType == MISSION_TRADE) {
                $FleetClass = $Owner ? 'colorMission16Own' : 'colorMission16Foreign';
            } elseif ($MissionType == MISSION_TRANSFER) {
                $FleetClass = $Owner ? 'colorMission17Own' : 'colorMission17Foreign';
            }
        } else {
            if ($MissionType == MISSION_COLONISATION) {
                $FleetClass = $Owner ? 'colorMission7OwnReturn' : 'colorMissionReturnForeign';
            } else {
                $FleetClass = $Owner ? 'colorMissionReturnOwn' : 'colorMissionReturnForeign';
            }
        }

        $EventString = '<span class="' . $FleetClass . '">' . $EventString . '</span>';

        if ($Status == FLEET_OUTWARD) {
            $Time = $fleetRow['arrival_time'];
        } elseif ($Status == FLEET_RETURN) {
            $Time = $fleetRow['return_time'];
        } elseif ($Status == FLEET_HOLD) {
            $Time = $fleetRow['stay_time'];
        } else {
            $Time = $this->state->runtime->timestamp;
        }

        $Rest   = $Time - $this->state->runtime->timestamp;
        return [$Rest, $EventString, $Time];
    }

    private function buildHostileFleetPlayerLink(array $fleetRow): string
    {
        $LNG = & $this->state->LNG;
        return htmlspecialchars((string) $fleetRow['own_username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false)
            . ' <a href="#" onclick="return Dialog.PM(' . $fleetRow['owner'] . ')">' . $LNG['PM'] . '</a>';
    }

    private function createFleetPopupedMissionLink(array $fleetRow, string $Texte, string $FleetType): string
    {
        $LNG = & $this->state->LNG;
        $FleetTotalC  = $fleetRow['resource_metal'] + $fleetRow['resource_crystal']
            + $fleetRow['resource_deuterium'];
        if ($FleetTotalC != 0 && !$this->IsPhalanx) {
            $textForBlind = $LNG['tech'][900] . ': ';
            $textForBlind .= floatToString($fleetRow['resource_metal']) . ' ' . $LNG['tech'][901];
            $textForBlind .= '; ' . floatToString($fleetRow['resource_crystal']) . ' ' . $LNG['tech'][902];
            $textForBlind .= '; ' . floatToString($fleetRow['resource_deuterium']) . ' ' . $LNG['tech'][903];

            $FRessource   = '<table class=\'tip-table\'>';
            $FRessource  .= '<tr><td>' . $LNG['tech'][901]
                . '</td><td>' . $this->viewHelpers()->prettyNumber($fleetRow['resource_metal'])
                . '</td></tr>';
            $FRessource  .= '<tr><td>' . $LNG['tech'][902]
                . '</td><td>' . $this->viewHelpers()->prettyNumber($fleetRow['resource_crystal'])
                . '</td></tr>';
            $FRessource  .= '<tr><td>' . $LNG['tech'][903]
                . '</td><td>' . $this->viewHelpers()->prettyNumber($fleetRow['resource_deuterium'])
                . '</td></tr>';
            $FRessource  .= '</table>';

            $MissionPopup  = '<a data-tooltip-content="' . $FRessource . '" class="tooltip ' . $FleetType . '">'
                . $Texte . '</a><span class="textForBlind"> (' . $textForBlind . ')</span>';
        } else {
            $MissionPopup  = $Texte;
        }

        return $MissionPopup;
    }

    private function createFleetPopupedFleetLink(array $fleetRow, string $Text, string $FleetType): string
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $resource = & $this->state->resource;
        $SpyTech        = $USER[$resource[106]];
        $Owner          = $fleetRow['owner'] == $this->userId;
        $FleetRec       = explode(';', $fleetRow['ships']);
        $FleetPopup     = '<a href="#" data-tooltip-content="<table class=\'tip-table\'>';
        $textForBlind   = '';
        if ($this->IsPhalanx || $SpyTech >= 2 || $Owner) {
            if ($SpyTech < 8 && !$Owner) {
                $addColon = $SpyTech >= 4 ? ": " : "";
                $FleetPopup     .= '<tr><td class=\'tip-wide\'>' . $LNG['cff_aproaching']
                    . $fleetRow['ship_amount'] . $LNG['cff_ships'] . $addColon . '</td></tr>';
                $textForBlind   = $LNG['cff_aproaching'] . $fleetRow['ship_amount'] . $LNG['cff_ships'] . $addColon;
            }
            $shipsData  = [];
            foreach ($FleetRec as $Item => $Group) {
                if (empty($Group)) {
                    continue;
                }

                $Ship    = explode(',', $Group);
                if ($Owner) {
                    $FleetPopup     .= '<tr><td>' . $LNG['tech'][$Ship[0]]
                        . ':</td><td>' . $this->viewHelpers()->prettyNumber($Ship[1]) . '</td></tr>';
                    $shipsData[]    = floatToString($Ship[1]) . ' ' . $LNG['tech'][$Ship[0]];
                } else {
                    if ($SpyTech >= 8) {
                        $FleetPopup     .= '<tr><td>' . $LNG['tech'][$Ship[0]]
                            . ':</td><td>' . $this->viewHelpers()->prettyNumber($Ship[1]) . '</td></tr>';
                        $shipsData[]    = floatToString($Ship[1]) . ' ' . $LNG['tech'][$Ship[0]];
                    } elseif ($SpyTech >= 4) {
                        $FleetPopup     .= '<tr><td class=\'tip-wide\'>' . $LNG['tech'][$Ship[0]]
                            . '</td></tr>';
                        $shipsData[]    = $LNG['tech'][$Ship[0]];
                    }
                }
            }
            $textForBlind   .= implode('; ', $shipsData);
        } else {
            $FleetPopup     .= '<tr><td class=\'tip-wide\'>' . $LNG['cff_no_fleet_data']
                . '</span></td></tr>';
            $textForBlind   = $LNG['cff_no_fleet_data'];
        }

        $FleetPopup  .= '</table>" class="tooltip ' . $FleetType . '">' . $Text . '</a><span class="textForBlind"> ('
            . $textForBlind . ')</span>';

        return $FleetPopup;
    }
}
