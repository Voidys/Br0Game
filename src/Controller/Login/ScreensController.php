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

use App\Routing\GameRoute;
use App\Content\ImageProcessor;
use App\Runtime\SafeFile;
use Symfony\Component\HttpFoundation\Response;
use DirectoryIterator;

/** Screenshot gallery. */
class ScreensController extends AbstractLoginController
{
    /**
     * Gallery of the screenshot files in public/styles/resource/images/login/screens/, sorted by path. The grid shows the
     * `-<w>w.webp` width variants through srcset (`bin/console app:images:variants styles/resource/images/login/screens
     * --widths=320,480,720`, run after replacing a screenshot); the link goes to the full-size file for the lightbox.
     */
    #[GameRoute('index', 'screens', 'screens')]
    public function show(): Response
    {
        $dir = 'public/styles/resource/images/login/screens/';
        $screenshots = [];
        foreach (new DirectoryIterator($dir) as $fileInfo) {
            /** @var DirectoryIterator $fileInfo */
            if (!$fileInfo->isFile() || ImageProcessor::isVariant($fileInfo->getFilename()) || ($size = SafeFile::imageSize($dir . $fileInfo->getFilename())) === false) {
                continue;
            }

            $url = 'styles/resource/images/login/screens/' . $fileInfo->getFilename();
            $screenshots[] = [
                'path'   => $url,
                'srcset' => ImageProcessor::srcset($dir . $fileInfo->getFilename(), $url, $size[0]),
                'width'  => $size[0],
                'height' => $size[1],
            ];
        }

        usort($screenshots, fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $this->render('login/page.screens.default.html.twig', [
            'screenshots' => $screenshots,
        ]);
    }
}
