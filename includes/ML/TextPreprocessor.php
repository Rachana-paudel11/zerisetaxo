<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * Text Preprocessor for PHP-Native ML Inference.
 * Implements scikit-learn compatible regex tokenization, stop-word filtering,
 * and n-gram extraction.
 */
class TextPreprocessor {

    /**
     * Whether to lowercase input text.
     */
    private bool $lowercase;

    /**
     * PCRE regex pattern for word token extraction.
     */
    private string $token_pattern;

    /**
     * Stop words dictionary for O(1) set lookup [word => true].
     */
    private array $stop_words_lookup;

    /**
     * Minimum and maximum n-gram length [min_n, max_n].
     */
    private array $ngram_range;

    /**
     * @param array $config Vectorizer configuration from model artifact.
     */
    public function __construct(array $config) {
        $this->lowercase = !empty($config['lowercase']);
        // Default to unicode word boundary regex with /u flag
        $this->token_pattern = '/\b\w\w+\b/u';
        $this->ngram_range   = $config['ngram_range'] ?? [1, 2];

        // Build O(1) stop-word lookup hash table
        $stop_words = $config['stop_words'] ?? [];
        $this->stop_words_lookup = array_fill_keys($stop_words, true);
    }

    /**
     * Normalizes text casing using UTF-8 multibyte lowercasing.
     *
     * @param string $text
     * @return string
     */
    public function clean_text(string $text): string {
        if ($this->lowercase) {
            return mb_strtolower($text, 'UTF-8');
        }
        return $text;
    }

    /**
     * Extracts raw tokens matching token_pattern.
     *
     * @param string $clean_text
     * @return array<string>
     */
    public function extract_raw_tokens(string $clean_text): array {
        if (preg_match_all($this->token_pattern, $clean_text, $matches)) {
            return $matches[0];
        }
        return [];
    }

    /**
     * Filters stop words from extracted tokens.
     *
     * @param array<string> $tokens
     * @return array<string>
     */
    public function filter_stop_words(array $tokens): array {
        if (empty($this->stop_words_lookup)) {
            return $tokens;
        }

        $filtered = [];
        foreach ($tokens as $token) {
            if (!isset($this->stop_words_lookup[$token])) {
                $filtered[] = $token;
            }
        }
        return $filtered;
    }

    /**
     * Generates unigrams and bigrams from filtered word list.
     *
     * @param array<string> $words
     * @return array<string>
     */
    public function generate_ngrams(array $words): array {
        $tokens = [];
        $min_n  = $this->ngram_range[0] ?? 1;
        $max_n  = $this->ngram_range[1] ?? 2;
        $count  = count($words);

        // 1-grams
        if ($min_n <= 1) {
            foreach ($words as $word) {
                $tokens[] = $word;
            }
        }

        // 2-grams
        if ($max_n >= 2 && $count > 1) {
            for ($i = 0; $i < $count - 1; $i++) {
                $tokens[] = $words[$i] . ' ' . $words[$i + 1];
            }
        }

        return $tokens;
    }

    /**
     * Executes the full preprocessing pipeline on raw text.
     *
     * @param string $text
     * @return array<string> List of final n-gram tokens.
     */
    public function preprocess(string $text): array {
        $clean   = $this->clean_text($text);
        $raw     = $this->extract_raw_tokens($clean);
        $filtered = $this->filter_stop_words($raw);
        return $this->generate_ngrams($filtered);
    }
}
