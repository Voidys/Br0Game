<?php

declare(strict_types=1);

namespace App\Content;

use App\Runtime\GameConfig;
use App\Runtime\LanguagePacks;
use App\Runtime\TableNames;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Editable default pages (contacts, data privacy, rules). A page is stored per language in
 * {prefix}pages; as long as no row exists the page shows the former hard-coded content
 * (config contact fields / text templates), so nothing changes until an admin edits it.
 */
final class ContentPages
{
    /** slug => former text template (null: built from the contact config) */
    public const SLUGS = [
        'contacts' => null,
        'dataPrivacy' => 'dataPrivacy',
        'rules' => 'rules',
    ];

    /** slug => translation key of the title (domain public) */
    private const TITLE_KEYS = [
        'contacts' => 'siteTitleDisclamer',
        'dataPrivacy' => 'siteTitleDataPrivacy',
        'rules' => 'siteTitleRules',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly TableNames $tables,
        private readonly HtmlContent $html,
        private readonly GameConfig $config,
        private readonly LanguagePacks $languagePacks,
        private readonly TranslatorInterface $translator,
    ) {}

    /** @return array<string, string> code => name */
    public function languages(): array
    {
        return $this->languagePacks->getNames();
    }

    /**
     * The page for a language: its row, else the English one, else any language; without any row
     * the former default content.
     *
     * @return array{slug: string, lang: string, title: string, content: string, edited: bool, updated_at: int, updated_by: string}
     */
    public function find(string $slug, string $lang): array
    {
        $this->assertSlug($slug);

        $rows = $this->rows($slug);
        $row = $rows[$lang] ?? $rows['en'] ?? ($rows !== [] ? reset($rows) : null);
        if ($row === null) {
            return $this->default($slug, $lang);
        }

        return [
            'slug' => $slug,
            'lang' => (string) $row['lang'],
            'title' => (string) $row['title'],
            'content' => $this->html->rich((string) $row['content']),
            'edited' => true,
            'updated_at' => (int) $row['updated_at'],
            'updated_by' => (string) $row['updated_by'],
        ];
    }

    /** Exactly that language (for the editor): its row or the default content. */
    public function findExact(string $slug, string $lang): array
    {
        $this->assertSlug($slug);

        $row = $this->rows($slug)[$lang] ?? null;

        return $row === null ? $this->default($slug, $lang) : $this->find($slug, $lang);
    }

    /** @return array<string, array{updated_at: int, updated_by: string}> lang => info, edited languages only */
    public function edited(string $slug): array
    {
        $this->assertSlug($slug);

        $info = [];
        foreach ($this->rows($slug) as $lang => $row) {
            $info[$lang] = ['updated_at' => (int) $row['updated_at'], 'updated_by' => (string) $row['updated_by']];
        }

        return $info;
    }

    /**
     * Stores the edited version of a page for one language (insert or update). The HTML is sanitized with the rich
     * profile, the title cut to 255 and the editor name to 64 characters.
     *
     * @throws \InvalidArgumentException for an unknown slug or language
     */
    public function save(string $slug, string $lang, string $title, string $html, string $user): void
    {
        $this->assertSlug($slug);
        $this->assertLang($lang);

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (slug, lang, title, content, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
                $this->tables->get('pages'),
            ),
            [$slug, $lang, mb_substr(trim($title), 0, 255), $this->html->rich($html), time(), mb_substr($user, 0, 64)],
        );
    }

    /** back to the default content */
    public function reset(string $slug, string $lang): void
    {
        $this->assertSlug($slug);

        $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE slug = ? AND lang = ?', $this->tables->get('pages')),
            [$slug, $lang],
        );
    }

    /** @return array<string, array<string, mixed>> lang => row */
    private function rows(string $slug): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                sprintf('SELECT * FROM %s WHERE slug = ?', $this->tables->get('pages')),
                [$slug],
            );
        } catch (\Throwable) {
            return []; // table missing (migration pending): defaults
        }

        $byLang = [];
        foreach ($rows as $row) {
            $byLang[(string) $row['lang']] = $row;
        }

        return $byLang;
    }

    /** Page row for a page that was never edited: translated title, generated default content, edited = false. */
    private function default(string $slug, string $lang): array
    {
        $title = $this->translator->trans(self::TITLE_KEYS[$slug], [], 'public', $lang);

        return [
            'slug' => $slug,
            'lang' => $lang,
            'title' => $title,
            'content' => $this->defaultContent($slug, $lang),
            'edited' => false,
            'updated_at' => 0,
            'updated_by' => '',
        ];
    }

    /**
     * Default HTML of a page: "contacts" is built from the legal-notice settings (address, phone, mail, notice),
     * "dataPrivacy" from the privacy template with live settings (privacyPolicy()), the others from the language
     * template (the issues link fills the one placeholder).
     */
    private function defaultContent(string $slug, string $lang): string
    {
        $t = fn(string $key): string => $this->translator->trans($key, [], 'public', $lang);
        $e = static fn(?string $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($slug === 'contacts') {
            $address = nl2br($e($this->config->get('disclamer_address', '')), false);
            $phone = $e($this->config->get('disclamer_phone', ''));
            $mail = $e($this->config->get('disclamer_mail', ''));
            $mailLink = $mail === '' ? '' : '<a href="mailto:' . $mail . '">' . $mail . '</a>';
            $notice = $this->html->rich($this->config->get('disclamer_notice', ''));

            return '<p><strong>' . $e($t('disclamerLabelAddress')) . '</strong><br>' . $address . '</p>'
                . '<p><strong>' . $e($t('disclamerLabelPhone')) . '</strong><br>' . $phone . '</p>'
                . '<p><strong>' . $e($t('disclamerLabelMail')) . '</strong><br>' . $mailLink . '</p>'
                . ($this->html->isEmpty($notice) ? '' : '<h2>' . $e($t('disclamerLabelNotice')) . '</h2>' . $notice);
        }

        if ($slug === 'rules' && (int) $this->config->get('rules_default', '1') === 0) {
            return ''; // blank rules chosen in the installer / settings: the operator writes their own
        }

        $template = self::SLUGS[$slug];
        if ($slug === 'dataPrivacy' && $this->config->get('privacy_region', 'eu') === 'other') {
            $template = 'dataPrivacy_general';
        }
        $text = $this->languagePacks->getTemplate($template, $lang);
        if ($slug === 'dataPrivacy') {
            return $this->privacyPolicy($text);
        }

        $urls = [];
        foreach (\App\Game\Core\CommunityLinks::KEYS as $key) {
            $urls[$key] = (string) ($this->config->get($key, '') ?? '');
        }

        return sprintf(\App\Game\Core\CommunityLinks::fill($text, $urls), $this->config->get('git_issues_link', '') ?? '');
    }

    /**
     * Fills the default privacy policy (templates/texts/<lang>/dataPrivacy.txt) with the live settings.
     *
     * Placeholders: {game_name}, {contacts_url}, {contact_mail} (mailto link), {deletion_days} (account
     * deletion waiting period), {inactive_days} (automatic deletion of inactive players),
     * {message_days} (message expiry). Optional parts between <!--name--> and <!--/name--> are kept
     * only while the feature is in use: "mail" (a contact address is set), "inactive" and "messages" (the period is greater than zero), "board" (the community board is enabled).
     */
    private function privacyPolicy(string $text): string
    {
        $e = static fn(?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $days = fn(string $key): int => max(0, (int) $this->config->get($key, '0'));
        $mail = trim((string) $this->config->get('disclamer_mail', ''));

        $features = [
            'mail' => $mail !== '',
            'inactive' => $days('del_user_automatic') > 0,
            'messages' => $days('message_delete_days') > 0,
            'board' => (int) $this->config->get('board_enabled', '1') !== 0,
        ];
        foreach ($features as $name => $active) {
            $text = (string) preg_replace('#<!--' . $name . '-->(.*?)<!--/' . $name . '-->#s', $active ? '$1' : '', $text);
        }

        return strtr($text, [
            '{game_name}' => $e($this->config->get('game_name', '')),
            '{contacts_url}' => './?page=disclamer',
            '{contact_mail}' => '<a href="mailto:' . $e($mail) . '">' . $e($mail) . '</a>',
            '{deletion_days}' => (string) $days('del_user_manually'),
            '{inactive_days}' => (string) $days('del_user_automatic'),
            '{message_days}' => (string) $days('message_delete_days'),
        ]);
    }

    /** @throws \InvalidArgumentException unless the slug is one of SLUGS */
    private function assertSlug(string $slug): void
    {
        if (!array_key_exists($slug, self::SLUGS)) {
            throw new \InvalidArgumentException('Unknown page "' . $slug . '".');
        }
    }

    /** @throws \InvalidArgumentException unless the language pack exists */
    private function assertLang(string $lang): void
    {
        if (!isset($this->languagePacks->getNames()[$lang])) {
            throw new \InvalidArgumentException('Unknown language "' . $lang . '".');
        }
    }
}
