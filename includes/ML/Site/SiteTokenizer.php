<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * Site-Specific Text Tokenizer for PHP-Native Site Learning.
 *
 * Lightweight tokenizer designed for site model training and inference:
 * - no external dependencies
 * - no vocabulary corpus limit (we learn what exists)
 * - English stop-word filtering
 * - unigrams + bigrams
 * - lowercasing + Unicode-safe word extraction
 */
class SiteTokenizer {

    /**
     * English stop words (minimal, balanced set for WordPress content).
     */
    private static array $STOP_WORDS = [
        'a','an','the','and','or','but','of','in','on','at','to','for','by','with',
        'is','it','its','as','be','are','was','were','been','have','has','had',
        'do','does','did','will','would','could','should','may','might','shall',
        'this','that','these','those','which','who','what','when','where','how',
        'not','no','nor','so','yet','both','either','neither','than','then',
        'about','above','after','before','between','into','from','through',
        'during','including','each','every','any','all','some','such','more',
        'most','other','another','same','just','than','can','if','than',
        'we','they','you','he','she','our','their','your','my','his','her',
        'i','me','us','him','her','them','up','out','get','use','also',
    ];

    private static ?array $stop_lookup = null;

    /**
     * Returns the stop-word lookup table (built once).
     */
    private static function stop_lookup(): array {
        if (self::$stop_lookup === null) {
            self::$stop_lookup = array_fill_keys(self::$STOP_WORDS, true);
        }
        return self::$stop_lookup;
    }

    /**
     * Tokenize raw text into filtered unigrams + bigrams.
     *
     * @param string $text
     * @param bool   $include_bigrams
     * @return string[]
     */
    public static function tokenize(string $text, bool $include_bigrams = true): array {
        // Lowercase + extract words (Unicode-safe, min length 2)
        $clean = mb_strtolower($text, 'UTF-8');
        // Require at least one letter (excludes pure-numeric tokens like "123")
        preg_match_all('/\b(?=[^\W\d_]*[a-z])\w{2,}\b/u', $clean, $m);
        $words = $m[0] ?? [];

        // Filter stop words
        $stop = self::stop_lookup();
        $filtered = [];
        foreach ($words as $w) {
            if (!isset($stop[$w])) {
                $filtered[] = $w;
            }
        }

        if (!$include_bigrams || count($filtered) < 2) {
            return $filtered;
        }

        // Add bigrams
        $tokens = $filtered;
        $n = count($filtered);
        for ($i = 0; $i < $n - 1; $i++) {
            $tokens[] = $filtered[$i] . ' ' . $filtered[$i + 1];
        }
        return $tokens;
    }
}
