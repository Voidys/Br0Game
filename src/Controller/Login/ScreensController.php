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

namespace App\Controller\Login;

use Symfony\Component\HttpFoundation\Response;
use DirectoryIterator;

/** Screenshot gallery. */
#[\App\Routing\GamePage('index.php', 'screens')]
#[\App\Routing\GamePage('index.php', 'screens', uniPrefix: true)]
class ScreensController extends AbstractLoginController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Gallery of the screenshot files in public/styles/resource/images/login/screens/ (with the thumbnail from `thumbnails/` when one exists), sorted by path.
     */
    public function show(): Response
    {
        $screenshots	= [];
        $directoryIterator = new DirectoryIterator('public/styles/resource/images/login/screens/');
        foreach ($directoryIterator as $fileInfo) {
            /** @var DirectoryIterator $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }

            $thumbnail = 'styles/resource/images/login/screens/' . $fileInfo->getFilename();
            if (file_exists('public/styles/resource/images/login/screens/thumbnails/' . $fileInfo->getFilename())) {
                $thumbnail = 'styles/resource/images/login/screens/thumbnails/' . $fileInfo->getFilename();
            }

            $screenshots[]	= [
                'path' 		=> 'styles/resource/images/login/screens/' . $fileInfo->getFilename(),
                'thumbnail' => $thumbnail,
            ];
        }

        usort($screenshots, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        $this->assign([
            'screenshots' => $screenshots,
        ]);

        return $this->render('login/page.screens.default.html.twig');
    }
}
