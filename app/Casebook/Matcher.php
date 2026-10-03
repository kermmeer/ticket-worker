<?php

namespace App\Casebook;

use App\Models\CasebookEntry;
use App\Models\Space;

/**
 * Finds the casebook entry a ticket most looks like, without asking a model: shared
 * words, weighted by where they appear in the entry (keywords, then title, then
 * symptoms) and by how rare they are across the casebook. Cheap enough to run on every
 * ticket of every sync. A hint for you and a head start for the agent, never a verdict.
 */
class Matcher
{
    /** A match needs this many different words in common, and this score. */
    public const MIN_SHARED = 2;

    public const MIN_SCORE = 2.0;

    private const WEIGHTS = ['keywords' => 3.0, 'title' => 2.0, 'symptoms' => 1.0];

    /** Words that say nothing about a problem, in the languages tickets come in. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'not', 'with', 'this', 'that', 'from', 'are', 'was', 'has', 'have', 'but', 'when',
        'after', 'before', 'can', 'cannot', 'does', 'doesn', 'don', 'will', 'there', 'their', 'what', 'which',
        'into', 'some', 'all', 'any', 'our', 'your', 'you', 'they', 'them', 'been', 'being', 'also', 'more',
        'het', 'een', 'van', 'voor', 'niet', 'met', 'dat', 'die', 'deze', 'zijn', 'wordt', 'worden', 'naar',
        'bij', 'ook', 'nog', 'maar', 'wel', 'kan', 'geen', 'als', 'over', 'heeft', 'hebben', 'werd', 'aan',
        'les', 'des', 'une', 'pour', 'pas', 'dans', 'sur', 'avec', 'qui', 'que', 'est', 'sont', 'par', 'mais',
        'det', 'der', 'som', 'ikke', 'med', 'til', 'paa', 'har', 'kan', 'ved', 'efter', 'eller', 'fra',
        'issue', 'problem', 'error', 'please', 'help', 'ticket',
    ];

    /** @var array<int, array<string, float>> entry id => word => weight */
    private array $words = [];

    /** @var array<string, float> */
    private array $rarity = [];

    /** @param iterable<CasebookEntry> $entries */
    public function __construct(iterable $entries)
    {
        $documentFrequency = [];

        foreach ($entries as $entry) {
            $weights = [];
            foreach (self::WEIGHTS as $field => $weight) {
                foreach (self::words((string) $entry->{$field}) as $word) {
                    $weights[$word] = max($weights[$word] ?? 0, $weight);
                }
            }
            $this->words[$entry->id] = $weights;
            foreach (array_keys($weights) as $word) {
                $documentFrequency[$word] = ($documentFrequency[$word] ?? 0) + 1;
            }
        }

        $count = max(1, count($this->words));
        foreach ($documentFrequency as $word => $frequency) {
            $this->rarity[$word] = log(1 + $count / $frequency) + 0.5;
        }
    }

    /**
     * The approved entries a space's tickets can match: those about its systems and
     * those about no system in particular. A space without systems gets them all.
     */
    public static function forSpace(Space $space): self
    {
        $systems = $space->systems()->pluck('systems.id');

        return new self(CasebookEntry::query()
            ->approved()
            ->when($systems->isNotEmpty(), fn ($query) => $query->where(
                fn ($query) => $query->whereNull('system_id')->orWhereIn('system_id', $systems),
            ))
            ->get());
    }

    /** @return array{id: int, score: float}|null */
    public function best(string $text): ?array
    {
        $words = array_unique(self::words($text));
        $best = null;

        foreach ($this->words as $id => $weights) {
            $shared = 0;
            $score = 0.0;
            foreach ($words as $word) {
                if (isset($weights[$word])) {
                    $shared++;
                    $score += $weights[$word] * $this->rarity[$word];
                }
            }

            if ($shared >= self::MIN_SHARED && $score >= self::MIN_SCORE && ($best === null || $score > $best['score'])) {
                $best = ['id' => $id, 'score' => round($score, 2)];
            }
        }

        return $best;
    }

    /**
     * Lower-case words of three letters or more, stop words out, and plurals folded:
     * "Invoices" matches "invoice", "facturen" matches "factuur" only through keywords.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $matches);
        $stop = array_flip(self::STOPWORDS);
        $words = [];

        foreach ($matches[0] as $word) {
            if (isset($stop[$word])) {
                continue;
            }
            $words[] = self::fold($word);
        }

        return $words;
    }

    private static function fold(string $word): string
    {
        if (mb_strlen($word) <= 4) {
            return $word;
        }

        return match (true) {
            str_ends_with($word, 'ies') => mb_substr($word, 0, -3).'y',
            str_ends_with($word, 'sses'), str_ends_with($word, 'xes') => mb_substr($word, 0, -2),
            str_ends_with($word, 's') && ! str_ends_with($word, 'ss') => mb_substr($word, 0, -1),
            default => $word,
        };
    }
}
