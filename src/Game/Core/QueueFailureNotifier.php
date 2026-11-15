<?php

namespace App\Game\Core;

/**
 * Builds and sends the "queue element could not be started" messages of the building and research queues
 * (not enough resources, no more levels, no more fields) to the player's build-list inbox.
 *
 * Collaborators: PlayerUtil (language lookup, sendMessage), ViewHelpers (number formatting).
 * Side effect: inserts a MESSAGE_TYPE_BUILD_LIST message; callers only use it when the user wants these messages
 * (users.hof == 1).
 */
class QueueFailureNotifier
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * The user's language, falling back to English when the user has none.
     */
    public function language(int|string $userId): Language
    {
        $LNG = $this->playerUtil()->getLanguage(null, $userId);
        if (empty($LNG)) {
            // Fallback language
            $LNG = new Language($this->configs, 'en');
            $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        }

        return $LNG;
    }

    /**
     * "Not enough resources" text: planet name/coordinates, current stock and the missing cost of $element.
     *
     * @param array<string, mixed> $planet
     * @param array<int, float|int> $costResources missing keys count as 0
     */
    public function notEnoughResources(Language $LNG, array $planet, int|string $element, array $costResources): string
    {
        foreach ([RESOURCE_METAL, RESOURCE_CRYSTAL, RESOURCE_DEUT] as $resourceId) {
            if (!isset($costResources[$resourceId])) {
                $costResources[$resourceId] = 0;
            }
        }
        $view = $this->viewHelpers();

        return sprintf(
            $LNG['sys_notenough_money'],
            $planet['name'],
            $planet['id'],
            $planet['galaxy'],
            $planet['system'],
            $planet['planet'],
            $LNG['tech'][$element],
            $view->prettyNumber($planet['metal']),
            $LNG['tech'][RESOURCE_METAL],
            $view->prettyNumber($planet['crystal']),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $view->prettyNumber($planet['deuterium']),
            $LNG['tech'][RESOURCE_DEUT],
            $view->prettyNumber($costResources[RESOURCE_METAL]),
            $LNG['tech'][RESOURCE_METAL],
            $view->prettyNumber($costResources[RESOURCE_CRYSTAL]),
            $LNG['tech'][RESOURCE_CRYSTAL],
            $view->prettyNumber($costResources[RESOURCE_DEUT]),
            $LNG['tech'][RESOURCE_DEUT],
        );
    }

    /**
     * "No more fields" text with a link to the planet's building page.
     *
     * @param array<string, mixed> $planet
     */
    public function noMoreFields(Language $LNG, array $planet, int|string $element): string
    {
        return sprintf(
            '%s <a href="./game?page=buildings&amp;cp=%d&amp;re=0">[%d:%d:%d]</a>: %s (%s)',
            $planet['name'],
            $planet['id'],
            $planet['galaxy'],
            $planet['system'],
            $planet['planet'],
            $LNG['bd_no_more_fields'],
            $LNG['tech'][$element],
        );
    }

    /**
     * Sends $message to the user's build-list inbox under $subjectKey (e.g. sys_buildlist / sys_techlist).
     */
    public function send(Language $LNG, int|string $userId, string $subjectKey, string $message, int|float $time): void
    {
        $this->playerUtil()->sendMessage(
            $userId,
            0,
            $LNG[$subjectKey],
            MESSAGE_TYPE_BUILD_LIST,
            $LNG['sys_buildlist_fail'],
            $message,
            $time,
        );
    }
}
