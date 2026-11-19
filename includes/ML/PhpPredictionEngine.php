<?php
namespace ZeriseTaxo\ML;

defined('ABSPATH') || exit;

/**
 * PHP-Native Machine Learning Prediction Engine.
 * Replaces the Python/FastAPI runtime dependency with an in-process,
 * zero-subprocess PHP inference engine.
 *
 * Implements PredictionEngineInterface for direct integration with WordPress
 * controllers, incorporating taxonomy mapping, ambiguity protection, and OOD safety.
 */
class PhpPredictionEngine implements PredictionEngineInterface {

    /**
     * Target category class labels.
     *
     * @var array<string>
     */
    private array $classes;

    /**
     * Calibrated classifier folds.
     *
     * @var array
     */
    private array $folds;

    /**
     * Inverted vocabulary mapping for diagnostics: [feature_index => term].
     *
     * @var array<int, string>
     */
    private array $inverted_vocab = [];

    /**
     * Common English topic synonyms for fuzzy taxonomy matching.
     *
     * @var array<string, array<string>>
     */
    private array $topic_synonyms = [
        'Tech'               => ['technology', 'technologies', 'tech', 'software', 'hardware', 'computers', 'laptops', 'gadgets', 'electronics', 'it', 'tech news', 'programming', 'web development', 'web dev', 'coding', 'devices', 'pc', 'wordpress', 'wp', 'cms', 'plugins', 'themes', 'web', 'internet', 'apps', 'applications'],
        'Marketing'          => ['seo', 'marketing', 'digital marketing', 'search engine optimization', 'content marketing', 'social media', 'advertising', 'ads', 'affiliate', 'branding', 'email marketing', 'traffic', 'copywriting', 'growth', 'pr', 'public relations'],
        'Gaming'             => ['games', 'gaming', 'video games', 'gamer', 'esports', 'pc gaming', 'game reviews', 'consoles', 'playstation', 'xbox', 'nintendo'],
        'Sport'              => ['sports', 'athletics', 'football', 'soccer', 'basketball', 'cricket', 'fitness', 'tennis', 'baseball', 'olympics'],
        'Food'               => ['food & dining', 'food and drink', 'cooking', 'recipes', 'cuisine', 'culinary', 'baking', 'restaurant', 'nutrition', 'dining'],
        'Travel'             => ['travel & tourism', 'tourism', 'destinations', 'vacations', 'adventures', 'trip', 'hotel', 'flight', 'backpacking'],
        'Business'           => ['business & finance', 'entrepreneurship', 'startups', 'corporate', 'economy', 'management', 'leadership', 'small business', 'enterprise'],
        'Finance'            => ['financial', 'investing', 'money', 'banking', 'economy', 'wealth', 'personal finance', 'stocks', 'accounting', 'taxes'],
        'Science'            => ['sciences', 'scientific', 'biology', 'physics', 'chemistry', 'astronomy', 'research', 'lab'],
        'Health'             => ['healthcare', 'health & wellness', 'wellness', 'medicine', 'medical', 'healthy living', 'diet', 'nutrition', 'mental health'],
        'Entertainment'      => ['entertainment & media', 'movies', 'cinema', 'television', 'tv', 'pop culture', 'celebrity', 'film', 'theater', 'shows'],
        'Politics'           => ['political', 'government', 'public policy', 'election', 'elections', 'policy', 'democracy'],
        'Education'          => ['learning', 'schools', 'university', 'academia', 'teaching', 'study', 'education & learning', 'tutorials', 'courses'],
        'DIY & Crafts'       => ['diy', 'crafts', 'do it yourself', 'tutorials', 'how to', 'handmade', 'woodworking', 'sewing', 'home improvement', 'hacks', 'tips & tricks'],
        'Photography'        => ['photo', 'photos', 'camera', 'cameras', 'photographer', 'photo editing', 'portrait', 'landscape photography', 'gear', 'lens'],
        'Fitness'            => ['workout', 'exercise', 'gym', 'training', 'bodybuilding', 'weight loss', 'cardio', 'yoga', 'crossfit', 'strength'],
        'Cryptocurrency'     => ['crypto', 'bitcoin', 'btc', 'ethereum', 'eth', 'blockchain', 'web3', 'defi', 'nft', 'altcoins', 'tokens'],
        'Beauty'             => ['beauty', 'skincare', 'makeup', 'cosmetics', 'hair', 'haircare', 'wellness', 'fragrance', 'grooming'],
        'Fashion'            => ['clothing', 'style', 'apparel', 'outfits', 'trends', 'shoes', 'accessories', 'wardrobe'],
        'Real Estate'        => ['property', 'housing', 'realty', 'real estate', 'mortgage', 'renting', 'home buying', 'properties'],
        'Home & Garden'      => ['interior design', 'gardening', 'home decor', 'decor', 'landscaping', 'furniture', 'renovation', 'patio'],
        'Parenting'          => ['family', 'kids', 'children', 'baby', 'parenting tips', 'motherhood', 'fatherhood', 'toddler'],
        'Pets & Animals'     => ['pets', 'dogs', 'cats', 'animals', 'puppy', 'veterinary', 'pet care', 'wildlife', 'dog training'],
        'Automotive'         => ['cars', 'vehicles', 'automotive', 'auto', 'driving', 'motorsport', 'mechanic', 'electric vehicles', 'ev'],
        'Aviation'           => ['aviation', 'airplanes', 'flights', 'aircraft', 'airlines', 'pilots', 'aerospace'],
        'Music'              => ['songs', 'albums', 'musical', 'guitar', 'piano', 'singing', 'audio', 'bands', 'artists'],
        'Literature'         => ['books', 'reading', 'novel', 'poetry', 'literature', 'fiction', 'non-fiction', 'authors', 'book reviews'],
        'Visual Arts'        => ['art', 'artwork', 'painting', 'drawing', 'illustration', 'graphic design', 'fine art', 'sculpture'],
        'Environment'        => ['sustainability', 'ecology', 'climate change', 'green living', 'conservation', 'environment', 'nature', 'renewable'],
        'Energy'             => ['solar', 'wind energy', 'clean energy', 'electricity', 'power', 'oil & gas', 'nuclear energy', 'renewables'],
        'Law & Legal'        => ['law', 'legal', 'attorney', 'lawyer', 'contracts', 'regulations', 'compliance', 'court', 'justice'],
        'Architecture'       => ['architect', 'buildings', 'architectural', 'construction', 'structures', 'urban design'],
        'Agriculture'        => ['farming', 'crops', 'livestock', 'agro', 'harvest', 'organic farming', 'agritech'],
        'Logistics'          => ['supply chain', 'shipping', 'freight', 'transportation', 'delivery', 'warehouse', 'logistics'],
        'Manufacturing'      => ['manufacturing', 'factory', 'industrial', 'production', 'machinery', 'automation', 'assembly'],
        'Marine & Ocean'     => ['marine', 'ocean', 'sea', 'scuba', 'diving', 'maritime', 'sailing', 'coastal'],
        'Media & Journalism' => ['journalism', 'news', 'press', 'media', 'reporting', 'podcast', 'broadcasting', 'publishing'],
        'Military & Defense' => ['military', 'defense', 'army', 'navy', 'air force', 'security', 'weapons', 'armed forces'],
        'Philosophy'         => ['philosophy', 'ethics', 'morality', 'logic', 'philosophical', 'thought', 'epistemology'],
        'Psychology'         => ['psychology', 'mental health', 'cognitive', 'therapy', 'counseling', 'behavior', 'mindset'],
        'Space'              => ['space', 'astronomy', 'nasa', 'cosmos', 'universe', 'rockets', 'planets', 'galaxies'],
    ];

    /**
     * Pipeline components.
     */
    private TextPreprocessor $preprocessor;
    private TfidfVectorizer $vectorizer;
    private LinearClassifier $classifier;
    private ProbabilityCalibrator $calibrator;

    /**
     * Lazy loading state.
     */
    private ?string $model_path = null;
    private bool $is_loaded     = false;

    /**
     * CSV random access weights state (Option 1 - WordPress.org compatible).
     */
    private bool $is_csv            = false;
    private string $weights_csv_path = '';
    private array $offsets          = [];

    /**
     * Binary packed weights state.
     */
    private bool $is_binary     = false;
    private string $weights_bin = '';
    private string $precision   = 'float64';

    /**
     * Instantiates engine from validated model array or lazy model path.
     *
     * @param array|string|null $model_data_or_path Array data, string path, or null.
     */
    public function __construct($model_data_or_path = null) {
        if (is_string($model_data_or_path)) {
            $this->model_path = $model_data_or_path;
            $this->is_loaded  = false;
        } elseif (is_array($model_data_or_path)) {
            $this->init_from_data($model_data_or_path);
            $this->is_loaded = true;
        }
    }

    /**
     * Initializes internal pipeline components from validated model data array.
     *
     * @param array $model_data
     */
    private function init_from_data(array $model_data): void {
        $this->classes = $model_data['classes'];
        $this->folds   = $model_data['classifier']['folds'];

        $this->preprocessor = new TextPreprocessor($model_data['vectorizer']);
        $this->vectorizer   = new TfidfVectorizer($model_data['vectorizer'], $this->preprocessor);
        $this->classifier   = new LinearClassifier();
        $this->calibrator   = new ProbabilityCalibrator();

        // Check if CSV weights or packed binary weights are present
        if (!empty($model_data['is_csv']) && !empty($model_data['weights_csv_path']) && !empty($model_data['offsets'])) {
            $this->is_csv           = true;
            $this->weights_csv_path = $model_data['weights_csv_path'];
            $this->offsets          = $model_data['offsets'];
            $this->is_binary        = false;
            $this->weights_bin      = '';
            $this->precision        = $model_data['precision'] ?? 'float32';
        } elseif (!empty($model_data['is_binary']) && !empty($model_data['weights_bin'])) {
            $this->is_csv           = false;
            $this->weights_csv_path = '';
            $this->offsets          = [];
            $this->is_binary        = true;
            $this->weights_bin      = $model_data['weights_bin'];
            $this->precision        = $model_data['precision'] ?? 'float64';
        } else {
            $this->is_csv           = false;
            $this->weights_csv_path = '';
            $this->offsets          = [];
            $this->is_binary        = false;
            $this->weights_bin      = '';
            $this->precision        = 'float64';
        }

        // Invert vocabulary for feature introspection
        if (!empty($model_data['vectorizer']['vocabulary'])) {
            $this->inverted_vocab = array_flip($model_data['vectorizer']['vocabulary']);
        }
    }

    /**
     * Ensures model is loaded into memory. Triggered lazily on first prediction.
     *
     * @throws \RuntimeException If model cannot be loaded.
     */
    public function ensure_loaded(): void {
        if ($this->is_loaded) {
            return;
        }
        if (empty($this->model_path)) {
            throw new \RuntimeException('PhpPredictionEngine has no model path configured.');
        }
        $data = ModelLoader::load($this->model_path);
        $this->init_from_data($data);
        $this->is_loaded = true;
    }

    /**
     * Returns whether the model has been loaded into memory.
     *
     * @return bool
     */
    public function is_loaded(): bool {
        return $this->is_loaded;
    }

    /**
     * Factory method to load model from disk and instantiate engine.
     * Supports lazy loading by default to eliminate bootstrap memory overhead.
     *
     * @param string $model_path Path to model artifact (JSON file or runtime directory).
     * @param bool   $lazy       Whether to defer loading until first prediction (default true).
     * @return self
     */
    public static function from_file(string $model_path, bool $lazy = true): self {
        if ($lazy) {
            return new self($model_path);
        }
        $data = ModelLoader::load($model_path);
        return new self($data);
    }

    /**
     * Executes prediction and returns full WordPress-ready prediction schema.
     * Implements PredictionEngineInterface.
     *
     * @param string $text    Post text (title + body).
     * @param array  $context Contextual parameters.
     * @return array
     */
    public function predict_category(string $text, array $context = []): array {
        $this->ensure_loaded();
        $clean_text = trim($text);
        $word_count = str_word_count($clean_text);
        if ($clean_text === '' || strlen($clean_text) < 3 || $word_count < 3) {
            return [
                'tier_used'            => 1,
                'tier_name'            => 'PHP Native Core ML',
                'result_type'          => 'none',
                'category'             => '',
                'suggested_category'   => '',
                'confidence'           => 0.0,
                'reasoning'            => 'Post text contains insufficient content for classification (at least 3 words required).',
                'state'                => 'out_of_distribution',
                'runner_up_category'   => '',
                'runner_up_confidence' => null,
                'margin'               => null,
                'is_ood'               => true,
                'decision_source'      => 'php_native',
                'scores'               => [],
                'tier_breakdown'       => [
                    'word_count' => $word_count,
                ],
            ];
        }

        // Title weighting: boost title terms (x3) so short drafts or long content don't drown out key topic words
        $title = $context['title'] ?? '';
        if (empty($title)) {
            $lines = explode("\n", $clean_text);
            $title = !empty($lines[0]) ? trim($lines[0]) : '';
        }
        $inference_text = !empty($title) ? ($title . ' ' . $title . ' ' . $title . "\n\n" . $clean_text) : $clean_text;

        // 1. Raw statistical inference
        $raw = $this->predict($inference_text, 10);

        // Polysemy & Contextual Disambiguation
        // A. Travel vs Corporate Governance:
        // Words like "board" (board of directors), "outside" (outside counsel), "effort" heavily boost Travel.
        // If text contains corporate governance terms and no genuine travel terms, neutralize Travel's polysemous weight.
        $text_lower = mb_strtolower($clean_text, 'UTF-8');
        $has_corporate = (bool) preg_match('/\b(board of directors|directors|ceo|shareholders|counsel|corporate governance|committee of independent|independent counsel|economic security)\b/u', $text_lower);
        $has_genuine_travel = (bool) preg_match('/\b(travel|trip|flight|hotel|tour|destination|vacation|resort|airline|airport|cruise|beach|tourism|passport|itinerary|luggage|baggage|transit)\b/u', $text_lower);
        $has_tech_entities = (bool) preg_match('/\b(nvidia|intel|amd|gpu|gpus|semiconductor|semiconductors|jensen huang|artificial intelligence|microprocessor|microchip)\b/u', $text_lower);

        if ($has_corporate && !$has_genuine_travel && isset($raw['probabilities']['Travel'])) {
            $raw['probabilities']['Travel'] *= 0.1;
        }
        if ($has_tech_entities && isset($raw['probabilities']['Tech'])) {
            $raw['probabilities']['Tech'] += 0.35;
        }

        // Re-normalize probabilities and update top slices if disambiguation adjustments occurred
        if (($has_corporate && !$has_genuine_travel) || $has_tech_entities) {
            $sum_p = array_sum($raw['probabilities']);
            if ($sum_p > 0) {
                foreach ($raw['probabilities'] as $cat_key => $val) {
                    $raw['probabilities'][$cat_key] = $val / $sum_p;
                }
            }
            arsort($raw['probabilities']);
            $re_ranked = [];
            foreach ($raw['probabilities'] as $cat_key => $prob_val) {
                $re_ranked[] = ['category' => $cat_key, 'confidence' => $prob_val];
            }
            $raw['top_1'] = $re_ranked[0];
            $raw['top_3'] = array_slice($re_ranked, 0, 3);
            $raw['top_k'] = array_slice($re_ranked, 0, 10);
        }

        $active_features = $raw['active_features'];
        $top1            = $raw['top_1'];
        $top2            = $raw['top_3'][1] ?? null;

        $raw_category   = (string)$top1['category'];
        $raw_confidence = (float)$top1['confidence'];
        $top2_cat       = $top2 ? (string)$top2['category'] : '';
        $top2_conf      = $top2 ? (float)$top2['confidence'] : 0.0;
        $margin         = $top2 ? ($raw_confidence - $top2_conf) : $raw_confidence;

        $available_categories = $context['available_categories'] ?? [];
        $confidence_threshold = (float)($context['confidence_threshold'] ?? 0.75);

        // 2. Safety Check: Out-of-Distribution / Nonsense / Symbols / Sparse Topical Signals
        // If 0 active features match the 20,776 trained vocabulary, input is completely ungrounded
        if (empty($active_features)) {
            return [
                'tier_used'            => 1,
                'tier_name'            => 'PHP Native Core ML',
                'result_type'          => 'none',
                'category'             => '',
                'suggested_category'   => '',
                'confidence'           => 0.0,
                'reasoning'            => 'Content contains no recognizable topical terms.',
                'state'                => 'out_of_distribution',
                'runner_up_category'   => '',
                'runner_up_confidence' => null,
                'margin'               => 0.0,
                'is_ood'               => true,
                'decision_source'      => 'php_native',
                'scores'               => $raw['probabilities'],
                'tier_breakdown'       => [
                    'active_feature_count' => 0,
                    'raw_top1'             => $raw_category,
                ],
            ];
        }

        // Single isolated topical signal guard: A single isolated vocabulary term is insufficient to reliably categorize an entire post.
        if (count($active_features) < 2) {
            return [
                'tier_used'            => 1,
                'tier_name'            => 'PHP Native Core ML',
                'result_type'          => 'none',
                'category'             => '',
                'suggested_category'   => '',
                'confidence'           => $raw_confidence,
                'reasoning'            => 'Content contains insufficient distinct topical signals for automatic categorization.',
                'state'                => 'out_of_distribution',
                'runner_up_category'   => '',
                'runner_up_confidence' => null,
                'margin'               => $margin,
                'is_ood'               => true,
                'decision_source'      => 'php_native',
                'scores'               => $raw['probabilities'],
                'tier_breakdown'       => [
                    'active_feature_count' => count($active_features),
                    'raw_top1'             => $raw_category,
                ],
            ];
        }

        // Low statistical confidence threshold for OOD
        // Sparse content (< 5 active features) requires >= 0.35 to prevent false-positive guesses.
        // Substantial content (>= 5 active features) with leading probability (>= 0.18)
        // is statistically significant across 42 classes (random chance = 2.4%).
        $feature_count = count($active_features);
        $min_confidence_floor = ($feature_count >= 5) ? 0.18 : 0.35;

        if ($raw_confidence < $min_confidence_floor) {
            return [
                'tier_used'            => 1,
                'tier_name'            => 'PHP Native Core ML',
                'result_type'          => 'none',
                'category'             => '',
                'suggested_category'   => '',
                'confidence'           => $raw_confidence,
                'reasoning'            => sprintf('Statistical confidence (%.1f%%) is below the threshold for reliable category assignment.', $raw_confidence * 100),
                'state'                => 'out_of_distribution',
                'runner_up_category'   => '',
                'runner_up_confidence' => null,
                'margin'               => $margin,
                'is_ood'               => true,
                'decision_source'      => 'php_native',
                'scores'               => $raw['probabilities'],
                'tier_breakdown'       => [
                    'active_feature_count' => $feature_count,
                    'raw_top1'             => $raw_category,
                ],
            ];
        }

        // 3. Ambiguity check: small margin between top 1 and runner up, or in the exploratory band (< 0.48)
        $ambiguity_threshold = isset($context['ambiguity_margin'])
            ? (float) $context['ambiguity_margin']
            : (((float) get_option('zerisetaxo_ambiguity_margin', 12)) / 100.0);
        $is_ambiguous = ($margin < $ambiguity_threshold || $raw_confidence < 0.48);

        // 4. Map global trained topics to actual WordPress site taxonomy
        // Iterate through ranked candidate predictions to find all categories that exist on the site
        $mapped_category       = '';
        $mapped_category_conf  = 0.0;
        $mapped_runner_up      = '';
        $mapped_runner_up_conf = 0.0;
        $candidate_categories  = [];

        foreach ($raw['top_k'] as $cand) {
            $cat_name = (string) $cand['category'];
            $cat_conf = (float) $cand['confidence'];
            $wp_match = $this->map_to_wordpress_category($cat_name, $available_categories);
            if (!empty($wp_match)) {
                if (!isset($candidate_categories[$wp_match])) {
                    $candidate_categories[$wp_match] = [
                        'category'   => $wp_match,
                        'confidence' => $cat_conf,
                    ];
                }
                if (empty($mapped_category)) {
                    $mapped_category      = $wp_match;
                    $mapped_category_conf = $cat_conf;
                } elseif (empty($mapped_runner_up) && $wp_match !== $mapped_category) {
                    $mapped_runner_up      = $wp_match;
                    $mapped_runner_up_conf = $cat_conf;
                }
            }
        }

        // If mapped category does not exist in the site's WordPress categories:
        if (empty($mapped_category)) {
            // Strict safety gate: Proposing a BRAND NEW category requires high confidence (>= 0.60) and clear margin (>= 0.12)
            // Low-confidence guesses (< 60%) must NEVER propose creating junk categories on a user's site.
            if ($raw_confidence < 0.60 || $margin < 0.12) {
                return [
                    'tier_used'            => 1,
                    'tier_name'            => 'PHP Native Core ML',
                    'result_type'          => 'none',
                    'category'             => '',
                    'suggested_category'   => '',
                    'confidence'           => $raw_confidence,
                    'reasoning'            => sprintf("Content does not strongly match any existing category (confidence %.1f%%).", $raw_confidence * 100),
                    'state'                => 'out_of_distribution',
                    'runner_up_category'   => '',
                    'runner_up_confidence' => null,
                    'margin'               => $margin,
                    'is_ood'               => true,
                    'decision_source'      => 'php_native',
                    'scores'               => $raw['probabilities'],
                    'tier_breakdown'       => [
                        'global_class'         => $raw_category,
                        'active_feature_count' => count($active_features),
                    ],
                ];
            }

            return [
                'tier_used'            => 1,
                'tier_name'            => 'PHP Native Core ML',
                'result_type'          => 'new_category_suggestion',
                'category'             => '',
                'suggested_category'   => $raw_category,
                'confidence'           => $raw_confidence,
                'reasoning'            => sprintf("Identified topic '%s', but no matching category exists in your WordPress taxonomy.", $raw_category),
                'state'                => 'no_existing_category',
                'runner_up_category'   => '',
                'runner_up_confidence' => null,
                'margin'               => $margin,
                'is_ood'               => false,
                'decision_source'      => 'php_native',
                'scores'               => $raw['probabilities'],
                'tier_breakdown'       => [
                    'global_class'         => $raw_category,
                    'active_feature_count' => count($active_features),
                ],
            ];
        }

        // Category exists in WordPress! Determine final state
        $state = ($is_ambiguous && !empty($mapped_runner_up)) ? 'ambiguous' : ($raw_confidence >= $confidence_threshold ? 'confident' : 'good_match');
        $feature_count = count($active_features);
        $signal_phrase = ($feature_count === 1) ? '1 core topical signal' : sprintf('%d core topical signals', $feature_count);
        $reasoning = ($state === 'ambiguous')
            ? sprintf("Close match between '%s' and '%s'. Please review.", $mapped_category, $mapped_runner_up)
            : sprintf("Categorized as '%s' based on %s.", $mapped_category, $signal_phrase);

        return [
            'tier_used'            => 1,
            'tier_name'            => 'PHP Native Core ML',
            'result_type'          => 'existing',
            'category'             => $mapped_category,
            'suggested_category'   => '',
            'confidence'           => $mapped_category_conf ?: $raw_confidence,
            'reasoning'            => $reasoning,
            'state'                => $state,
            'runner_up_category'   => $mapped_runner_up,
            'runner_up_confidence' => $mapped_runner_up_conf ?: null,
            'candidate_categories' => array_values($candidate_categories),
            'margin'               => $margin,
            'is_ood'               => false,
            'decision_source'      => 'php_native',
            'scores'               => $raw['probabilities'],
            'tier_breakdown'       => [
                'active_features'      => array_slice($active_features, 0, 8),
                'active_feature_count' => count($active_features),
                'raw_margin'           => $margin,
            ],
        ];
    }

    /**
     * Maps a global model topic class to an existing WordPress taxonomy category.
     * Checks exact match (case-insensitive) and curated topic synonyms.
     *
     * @param string        $global_class
     * @param array<string> $available_categories
     * @return string|null Matching WordPress category name or null if none exist.
     */
    public function map_to_wordpress_category(string $global_class, array $available_categories): ?string {
        if (empty($available_categories)) {
            return null;
        }

        $global_lower = strtolower($global_class);

        // 1. Exact case-insensitive match
        foreach ($available_categories as $wp_cat) {
            if (strtolower($wp_cat) === $global_lower) {
                return $wp_cat;
            }
        }

        // 2. Curated topic synonym / variation match
        $synonyms = $this->topic_synonyms[$global_class] ?? [];
        foreach ($available_categories as $wp_cat) {
            $wp_lower = strtolower($wp_cat);
            foreach ($synonyms as $syn) {
                if ($wp_lower === $syn || str_contains($wp_lower, $syn)) {
                    return $wp_cat;
                }
            }
        }

        // 3. Substring containment match
        foreach ($available_categories as $wp_cat) {
            $wp_lower = strtolower($wp_cat);
            if (str_contains($wp_lower, $global_lower) || str_contains($global_lower, $wp_lower)) {
                return $wp_cat;
            }
        }

        return null;
    }

    /**
     * Executes raw inference on the input text and returns statistical category predictions.
     *
     * @param string $text  Post title and content text.
     * @param int    $top_k Number of top category suggestions to return (default: 3).
     * @return array Standardized prediction result array.
     */
    public function predict(string $text, int $top_k = 3): array {
        $this->ensure_loaded();

        // 1. TF-IDF vectorization
        $sparse_vector = $this->vectorizer->transform($text);

        $num_classes = count($this->classes);
        $num_folds   = count($this->folds);

        $fold_probs = [];
        $fold_dfs   = [];

        // 2. Compute margins and calibrated probabilities for each fold
        if ($this->is_csv && !empty($this->weights_csv_path) && !empty($this->offsets)) {
            $fold_dfs = $this->classifier->compute_csv_decision_functions(
                $sparse_vector,
                $this->folds,
                $this->weights_csv_path,
                $this->offsets
            );
            foreach ($this->folds as $f => $fold) {
                $fold_probs[] = $this->calibrator->calibrate($fold_dfs[$f], $fold['calibration']);
            }
        } elseif ($this->is_binary && !empty($this->weights_bin)) {
            $fold_dfs = $this->classifier->compute_binary_decision_functions(
                $sparse_vector,
                $this->folds,
                $this->weights_bin,
                $this->precision
            );
            foreach ($this->folds as $f => $fold) {
                $fold_probs[] = $this->calibrator->calibrate($fold_dfs[$f], $fold['calibration']);
            }
        } else {
            foreach ($this->folds as $fold) {
                $margins = $this->classifier->compute_decision_functions($sparse_vector, $fold);
                $probs   = $this->calibrator->calibrate($margins, $fold['calibration']);

                $fold_dfs[]   = $margins;
                $fold_probs[] = $probs;
            }
        }

        // 3. Ensemble averaging across folds: P(class = c) = (1 / K) * sum(P_k(class = c))
        $final_probs = array_fill(0, $num_classes, 0.0);
        for ($c = 0; $c < $num_classes; $c++) {
            $sum_class = 0.0;
            for ($f = 0; $f < $num_folds; $f++) {
                $sum_class += $fold_probs[$f][$c];
            }
            $final_probs[$c] = $sum_class / (float)$num_folds;
        }

        // 4. Map probabilities to named categories
        $probabilities = [];
        $ranked = [];
        for ($c = 0; $c < $num_classes; $c++) {
            $cat_name = $this->classes[$c];
            $prob_val = $final_probs[$c];
            $probabilities[$cat_name] = $prob_val;
            $ranked[] = [
                'category'   => $cat_name,
                'confidence' => $prob_val,
                'index'      => $c
            ];
        }

        // 5. Sort descending by confidence score
        usort($ranked, function ($a, $b) {
            if ($a['confidence'] === $b['confidence']) {
                return 0;
            }
            return ($a['confidence'] > $b['confidence']) ? -1 : 1;
        });

        // 6. Active features introspection
        $active_features = [];
        foreach ($sparse_vector as $feat_idx => $tfidf_val) {
            $active_features[] = [
                'index' => $feat_idx,
                'term'  => $this->inverted_vocab[$feat_idx] ?? '',
                'tfidf' => $tfidf_val
            ];
        }
        usort($active_features, fn($a, $b) => $a['index'] <=> $b['index']);

        // Build top_k slice
        $top_slice = array_slice($ranked, 0, max(1, $top_k));
        $clean_top_slice = array_map(function ($item) {
            return [
                'category'   => $item['category'],
                'confidence' => $item['confidence']
            ];
        }, $top_slice);

        return [
            'probabilities'           => $probabilities,
            'top_1'                   => $clean_top_slice[0],
            'top_3'                   => array_slice($clean_top_slice, 0, 3),
            'top_k'                   => $clean_top_slice,
            'active_features'         => $active_features,
            'fold_decision_functions' => $fold_dfs,
            'fold_probabilities'      => $fold_probs
        ];
    }

    /**
     * Returns category class names list.
     *
     * @return array<string>
     */
    public function get_classes(): array {
        $this->ensure_loaded();
        return $this->classes;
    }

    /**
     * Returns total number of classes.
     *
     * @return int
     */
    public function get_num_classes(): int {
        $this->ensure_loaded();
        return count($this->classes);
    }

    /**
     * Returns whether the active model is using Format 2.0.0 CSV weights.
     *
     * @return bool
     */
    public function is_csv(): bool {
        $this->ensure_loaded();
        return $this->is_csv;
    }

    /**
     * Returns whether the active model is using Format 2.0.0 packed binary weights.
     *
     * @return bool
     */
    public function is_binary(): bool {
        $this->ensure_loaded();
        return $this->is_binary;
    }

    /**
     * Returns the active floating point precision ('float64' or 'float32').
     *
     * @return string
     */
    public function get_precision(): string {
        $this->ensure_loaded();
        return $this->precision;
    }

    /**
     * Returns inverted vocabulary map [feature_index => term].
     *
     * @return array<int, string>
     */
    public function get_inverted_vocabulary(): array {
        $this->ensure_loaded();
        return $this->inverted_vocab;
    }
}
