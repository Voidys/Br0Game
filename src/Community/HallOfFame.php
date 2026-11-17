<?php

declare(strict_types=1);

namespace App\Community;

use App\Game\Core\Database;

/**
 * Hall of fame data per universe (table HALL_OF_FAME): import, export and read of the ranking document.
 *
 * Document format (version 1): {"format":"hall-of-fame","version":1,"title":"...","sections":[{"label":"...","entries":[
 * {"label":"st_points","ranks":[{"pos":1,"label":"...","player":"CVXII","value":"3.487.020"}]}]}]}.
 * Labels are LNG keys or literal text; pos, rank label and value are optional, player may be empty.
 */
final class HallOfFame
{
    public const MAX_BYTES = 1048576;
    public const MAX_SECTIONS = 50;
    public const MAX_ENTRIES = 100;
    public const MAX_RANKS = 20;
    public const MAX_STRING = 200;

    public function __construct(private readonly Database $db) {}

    /**
     * Stored document of a universe with its update time, or null without data.
     *
     * @return array{title: string, sections: list<array<string, mixed>>, updated_at: int}|null
     */
    public function find(int $uni): ?array
    {
        $row = $this->db->selectSingle('SELECT `data`, `updated_at` FROM %%HALL_OF_FAME%% WHERE `universe` = :uni;', [':uni' => $uni]);
        if (empty($row)) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        if (!is_array($data)) {
            return null;
        }
        $data['updated_at'] = (int) $row['updated_at'];

        return $data;
    }

    /** @return list<int> ids of the universes that have data, ascending */
    public function universesWithData(): array
    {
        $ids = [];
        foreach ($this->db->select('SELECT `universe` FROM %%HALL_OF_FAME%% ORDER BY `universe` ASC;') as $row) {
            $ids[] = (int) $row['universe'];
        }

        return $ids;
    }

    /**
     * Validates and stores a JSON document for the universe, replacing existing data.
     *
     * @throws \InvalidArgumentException with a readable message when the document is invalid
     */
    public function import(int $uni, string $json, ?int $now = null): void
    {
        $doc = $this->validate($json);
        $this->db->replace('REPLACE INTO %%HALL_OF_FAME%% (`universe`, `data`, `updated_at`) VALUES (:uni, :data, :time);', [
            ':uni' => $uni,
            ':data' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':time' => $now ?? time(),
        ]);
    }

    /** Pretty JSON of the universe's document, or null without data. */
    public function export(int $uni): ?string
    {
        $doc = $this->find($uni);
        if ($doc === null) {
            return null;
        }
        unset($doc['updated_at']);

        return json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public function clear(int $uni): void
    {
        $this->db->delete('DELETE FROM %%HALL_OF_FAME%% WHERE `universe` = :uni;', [':uni' => $uni]);
    }

    /** Number of entries over all sections of a document. */
    public static function countEntries(array $doc): int
    {
        $n = 0;
        foreach ($doc['sections'] ?? [] as $section) {
            $n += count($section['entries'] ?? []);
        }

        return $n;
    }

    /**
     * Strict validation of a JSON document; returns the normalized document (known fields only).
     *
     * @return array{format: string, version: int, title: string, sections: list<array<string, mixed>>}
     * @throws \InvalidArgumentException
     */
    public function validate(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('file too large (max 1 MB)');
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('the document must be a JSON object');
        }
        if (($data['format'] ?? null) !== 'hall-of-fame') {
            throw new \InvalidArgumentException('"format" must be "hall-of-fame"');
        }
        if (($data['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('unsupported "version" (expected 1)');
        }
        $title = $this->text($data['title'] ?? '', 'title');
        $sections = $data['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)) {
            throw new \InvalidArgumentException('"sections" must be a list');
        }
        if (count($sections) > self::MAX_SECTIONS) {
            throw new \InvalidArgumentException('too many sections (max ' . self::MAX_SECTIONS . ')');
        }

        $outSections = [];
        foreach ($sections as $i => $section) {
            $path = 'sections[' . $i . ']';
            if (!is_array($section) || array_is_list($section)) {
                throw new \InvalidArgumentException($path . ' must be an object');
            }
            $entries = $section['entries'] ?? null;
            if (!is_array($entries) || !array_is_list($entries)) {
                throw new \InvalidArgumentException($path . '.entries must be a list');
            }
            if (count($entries) > self::MAX_ENTRIES) {
                throw new \InvalidArgumentException($path . ': too many entries (max ' . self::MAX_ENTRIES . ')');
            }
            $outEntries = [];
            foreach ($entries as $j => $entry) {
                $outEntries[] = $this->entry($entry, $path . '.entries[' . $j . ']');
            }
            $outSections[] = ['label' => $this->text($section['label'] ?? '', $path . '.label'), 'entries' => $outEntries];
        }

        return ['format' => 'hall-of-fame', 'version' => 1, 'title' => $title, 'sections' => $outSections];
    }

    /**
     * Validates one entry (label plus a list of ranks with optional pos, each text field cleaned) and returns it normalised.
     *
     * @throws \InvalidArgumentException when the entry or one of its ranks is malformed
     */
    private function entry(mixed $entry, string $path): array
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new \InvalidArgumentException($path . ' must be an object');
        }
        $label = $this->text($entry['label'] ?? null, $path . '.label');
        if ($label === '') {
            throw new \InvalidArgumentException($path . '.label must not be empty');
        }
        $ranks = $entry['ranks'] ?? null;
        if (!is_array($ranks) || !array_is_list($ranks)) {
            throw new \InvalidArgumentException($path . '.ranks must be a list');
        }
        if (count($ranks) > self::MAX_RANKS) {
            throw new \InvalidArgumentException($path . ': too many ranks (max ' . self::MAX_RANKS . ')');
        }
        $out = [];
        foreach ($ranks as $k => $rank) {
            $rp = $path . '.ranks[' . $k . ']';
            if (!is_array($rank) || array_is_list($rank)) {
                throw new \InvalidArgumentException($rp . ' must be an object');
            }
            $r = [];
            if (array_key_exists('pos', $rank)) {
                if (!is_int($rank['pos']) || $rank['pos'] < 1 || $rank['pos'] > 9999) {
                    throw new \InvalidArgumentException($rp . '.pos must be an integer between 1 and 9999');
                }
                $r['pos'] = $rank['pos'];
            }
            $rl = $this->text($rank['label'] ?? '', $rp . '.label');
            if ($rl !== '') {
                $r['label'] = $rl;
            }
            $r['player'] = $this->text($rank['player'] ?? '', $rp . '.player');
            $value = $this->text($rank['value'] ?? '', $rp . '.value');
            if ($value !== '') {
                $r['value'] = $value;
            }
            $out[] = $r;
        }

        return ['label' => $label, 'ranks' => $out];
    }

    private function text(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($path . ' must be a string');
        }
        $value = trim($value);
        if (mb_strlen($value) > self::MAX_STRING) {
            throw new \InvalidArgumentException($path . ' is longer than ' . self::MAX_STRING . ' characters');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            throw new \InvalidArgumentException($path . ' contains control characters');
        }

        return $value;
    }
}
