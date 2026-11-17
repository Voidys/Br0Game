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

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * FAQ pages: a searchable category index and one page per entry (translations/faq.<locale>.yaml,
 * `questions.<category>.<entry>.{title,body}`, `questions.<category>.{category,icon}`).
 */
class QuestionsController extends AbstractGameController
{
    /**
     * FAQ category overview with the data island for the client-side search.
     */
    #[GameRoute('game', 'game/faq', 'questions')]
    public function show(): Response
    {
        return $this->render('game/page.questions.default.html.twig', [
            'catalog' => $this->catalog(),
        ]);
    }

    /**
     * One FAQ entry (request: categoryID, questionID); unknown ids redirect to the overview.
     */
    #[GameRoute('game', 'game/faq/{categoryID}/{questionID}', 'questions', 'single', requirements: ['categoryID' => '\d+', 'questionID' => '\d+'])]
    public function single(): Response
    {
        $categoryID	= $this->input()->int('categoryID');
        $questionID	= $this->input()->int('questionID');

        $catalog = $this->catalog();
        $flat = [];
        foreach ($catalog as $category) {
            foreach ($category['entries'] as $entry) {
                $flat[] = ['category' => $category, 'entry' => $entry];
            }
        }

        $position = null;
        foreach ($flat as $index => $item) {
            if ($item['category']['id'] === $categoryID && $item['entry']['id'] === $questionID) {
                $position = $index;
                break;
            }
        }
        if ($position === null) {
            return $this->redirectToRoute('game_questions_show');
        }

        $current = $flat[$position];
        $entry = $current['entry'];

        return $this->render('game/page.questions.single.html.twig', [
            'category'	=> ['id' => $current['category']['id'], 'name' => $current['category']['name'], 'icon' => $current['category']['icon']],
            'entry'		=> [
                'id'	=> $entry['id'],
                'title'	=> $entry['title'],
                'body'	=> new \Twig\Markup($entry['body'], 'UTF-8'),
            ],
            'toc'		=> $entry['toc'],
            'prev'		=> $position > 0 ? $this->neighbour($flat[$position - 1]) : null,
            'next'		=> $position < count($flat) - 1 ? $this->neighbour($flat[$position + 1]) : null,
            'position'	=> $position + 1,
            'total'		=> count($flat),
        ]);
    }

    /**
     * @param array{category: array<string, mixed>, entry: array<string, mixed>} $item
     * @return array{categoryID: int, questionID: int, title: string, category: string}
     */
    private function neighbour(array $item): array
    {
        return [
            'categoryID'	=> $item['category']['id'],
            'questionID'	=> $item['entry']['id'],
            'title'			=> $item['entry']['title'],
            'category'		=> $item['category']['name'],
        ];
    }

    /**
     * The FAQ as an ordered list of categories (translation keys `questions.<id>.*`; the player's language over
     * English, so missing keys fall back to English). Per entry: the title, the HTML body (stored HTML), the plain
     * text of the body for the search, and the `<h2 id="...">` headings as a table of contents.
     *
     * @return list<array{id: int, name: string, icon: string, entries: list<array{id: int, title: string, body: string, text: string, toc: list<array{id: string, title: string}>}>}>
     */
    private function catalog(): array
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['FAQ']);

        $questions = $LNG['questions'] ?? [];
        if (!is_array($questions)) {
            return [];
        }

        $catalog = [];
        foreach ($questions as $categoryID => $category) {
            if (!is_numeric($categoryID) || !is_array($category)) {
                continue;
            }

            $entries = [];
            foreach ($category as $questionID => $entry) {
                if (!is_numeric($questionID) || !is_array($entry) || !isset($entry['title'], $entry['body'])) {
                    continue;
                }
                $entries[(int) $questionID] = $this->entryData((int) $questionID, (string) $entry['title'], (string) $entry['body']);
            }
            if ($entries === []) {
                continue;
            }
            ksort($entries);

            $icon = (string) ($category['icon'] ?? '');
            $catalog[(int) $categoryID] = [
                'id'		=> (int) $categoryID,
                'name'		=> (string) ($category['category'] ?? ''),
                'icon'		=> preg_match('/^[a-z0-9-]+$/', $icon) ? $icon : 'circle-help',
                'entries'	=> array_values($entries),
            ];
        }
        ksort($catalog);

        return array_values($catalog);
    }

    /**
     * @return array{id: int, title: string, body: string, text: string, toc: list<array{id: string, title: string}>}
     */
    private function entryData(int $id, string $title, string $body): array
    {
        $toc = [];
        if (preg_match_all('~<h2 id="([A-Za-z0-9_-]+)">(.*?)</h2>~s', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $toc[] = ['id' => $match[1], 'title' => trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
            }
        }

        $text = str_replace(['</p>', '</li>', '</h2>', '</tr>', '</td>', '</th>', '<br>', '<br />'], ' ', $body);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [
            'id'	=> $id,
            'title'	=> $title,
            'body'	=> $body,
            'text'	=> trim((string) preg_replace('/\s+/u', ' ', $text)),
            'toc'	=> $toc,
        ];
    }
}
