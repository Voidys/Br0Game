<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Login;

use Symfony\Component\HttpFoundation\Response;
use App\Content\ImageProcessor;
use App\Runtime\SafeFile;
use DateTimeImmutable;
use DateTimeZone;
use App\Runtime\ProjectPaths;

/** Start page: welcome text, login form, universe starts, the hourly meme and the latest news. */
#[\App\Routing\GamePage('index', 'index', default: true)]
class IndexController extends AbstractLoginController
{
    private \App\Community\DiscordWidget $discordWidget;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setDiscordWidget(\App\Community\DiscordWidget $discordWidget): void
    {
        $this->discordWidget = $discordWidget;
    }

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('light');
    }

    /**
     * Start page with login form. Request: ref (referral id: redirects to the registration with referralID), code (login
     * error number shown as message login_error_<code>). Lists universes that start within the next 28 days (or began up to
     * 14 days ago) with their start time in the universe timezone, plus the hourly meme and the latest news.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;

        $referralID = $this->http()->_GP('ref', 0);
        if (!empty($referralID)) {
            $this->redirectTo('./?page=register&referralID=' . $referralID);
        }

        $universeStarts = [];
        $now = time();
        foreach ($this->universe()->availableUniverses() as $uni) {
            $universeConfig = $this->configs->get((int) $uni);
            try {
                $timezone = new DateTimeZone($universeConfig->timezone);
            } catch (\Exception $e) {
                $timezone = new DateTimeZone(date_default_timezone_get());
            }

            $start = DateTimeImmutable::createFromFormat(
                '!d.m.Y H:i:s',
                $universeConfig->start . ' ' . $universeConfig->start_time,
                $timezone,
            );
            $parseErrors = DateTimeImmutable::getLastErrors();
            if ($start === false || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))) {
                continue;
            }

            $startTimestamp = $start->getTimestamp();
            if ($startTimestamp < $now - 14 * ONE_DAY || $startTimestamp > $now + 28 * ONE_DAY) {
                continue;
            }

            $universeStarts[] = [
                'name' => $universeConfig->uni_name,
                'date' => $start->format('d.m.Y H:i T'),
                'timestamp' => $startTimestamp,
            ];
        }

        $Code = $this->http()->_GP('code', 0);
        $loginCode = false;
        if (isset($LNG['login_error_' . $Code])) {
            $loginCode = $LNG['login_error_' . $Code];
        }
        $this->assign([
            'code'              => $loginCode,
            'rulesUrl'          => './?page=rules&lang=' . ($this->state->requestCookies['lang'] ?? 'de'),
            'universeStarts'    => $universeStarts,
        ] + $this->memes() + $this->latestNews() + $this->discordWidgetData());

        return $this->render('login/page.index.default.html.twig');
    }

    /**
     * Start page meme: the images in public/memes/ (drop a file in to add one) take turns, one per hour, in
     * file name order. Alt text: `home_meme_alt_<name>` (dashes become underscores), falling back to the
     * generic `home_meme_alt`. Width variants for `srcset` (`<name>-480w.webp`, `<name>-720w.webp`, next to the original as
     * the largest candidate) come from `bin/console app:images:variants memes --widths=480,720`; run it after dropping a new
     * meme in. Variants are never memes of their own (the glob skips `*-<w>w.*`).
     */
    private function memes(): array
    {
        $LNG = $this->state->LNG;
        $files = glob(ProjectPaths::ROOT . 'public/memes/*.{webp,avif,jpg,jpeg,png,gif}', GLOB_BRACE) ?: [];
        $files = array_filter($files, fn(string $file): bool => !ImageProcessor::isVariant($file));
        sort($files);
        $memes = [];
        foreach ($files as $file) {
            $size = SafeFile::imageSize($file);
            if ($size === false || $size[0] === 0) {
                continue;
            }

            $name = pathinfo($file, PATHINFO_FILENAME);
            $altKey = 'home_meme_alt_' . str_replace('-', '_', $name);
            $memes[] = [
                'src' => 'memes/' . rawurlencode(basename($file)) . '?v=' . filemtime($file),
                'width' => $size[0],
                'height' => $size[1],
                'srcset' => ImageProcessor::srcset($file, 'memes/' . rawurlencode(basename($file)), $size[0]),
                'alt' => isset($LNG[$altKey]) ? $LNG[$altKey] : $LNG['home_meme_alt'],
            ];
        }

        return [
            'meme' => $memes === [] ? null : $memes[intdiv($this->state->runtime->timestamp, 3600) % count($memes)],
        ];
    }

    /**
     * Discord box: online count of the server set in `discord_widget_guild` (cached by DiscordWidget), linked to
     * `discord_url` or else the widget's own invite. Null hides the box (no ID, widget disabled, Discord unreachable).
     */
    private function discordWidgetData(): array
    {
        $config = $this->configs->get();
        $widget = $this->discordWidget->fetch((string) ($config->discord_widget_guild ?? ''));
        if ($widget === null) {
            return ['discordWidget' => null];
        }
        $url = trim((string) ($config->discord_url ?? ''));

        return ['discordWidget' => [
            'online' => $widget['online'],
            'url' => $url !== '' ? $url : $widget['invite'],
        ]];
    }

    /** the three newest published news for the start page teaser panel */
    private function latestNews(): array
    {
        $LNG = $this->state->LNG;
        $items = [];
        $rows = $this->db->select("SELECT id, title, date FROM %%NEWS%% WHERE status = 1 AND date <= :now ORDER BY date DESC, id DESC LIMIT 3;", [
            ':now' => $this->state->runtime->timestamp,
        ]);
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'),
                'date' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $row['date']),
            ];
        }

        return ['latestNews' => $items];
    }
}
