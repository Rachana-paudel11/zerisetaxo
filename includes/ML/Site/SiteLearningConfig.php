<?php
namespace ZeriseTaxo\ML\Site;

defined('ABSPATH') || exit;

/**
 * Centralized Configuration and Thresholds for Site-Adaptive Learning & Cold Start.
 *
 * Keeps all thresholds in a single configurable object instead of hardcoding
 * arbitrary numbers throughout the codebase.
 */
class SiteLearningConfig {

    // Default Threshold Constants
    const DEFAULT_MIN_POSTS_FOR_TRAINING         = 10;
    const DEFAULT_MIN_POSTS_FOR_MATURE_MODEL     = 25;
    const DEFAULT_MIN_CATEGORIES_FOR_TRAINING    = 2;
    const DEFAULT_MIN_LABELLED_POSTS_TRAINING    = 5;
    const DEFAULT_MIN_LABELLED_POSTS_MATURE      = 20;

    // Option keys for WordPress settings
    const OPTION_MIN_POSTS_TRAINING      = 'zerisetaxo_min_posts_training';
    const OPTION_MIN_POSTS_MATURE        = 'zerisetaxo_min_posts_mature';
    const OPTION_MIN_CATEGORIES_TRAINING = 'zerisetaxo_min_categories_training';
    const OPTION_MIN_LABELLED_TRAINING   = 'zerisetaxo_min_labelled_training';

    private int $min_posts_for_training;
    private int $min_posts_for_mature_model;
    private int $min_categories_for_training;
    private int $min_labelled_posts_for_training;
    private int $min_labelled_posts_for_mature;

    public function __construct(array $overrides = []) {
        $get_opt = function($key, $default) {
            return function_exists('get_option') ? get_option($key, $default) : $default;
        };
        $this->min_posts_for_training = (int) ($overrides['min_posts_for_training'] ?? $get_opt(self::OPTION_MIN_POSTS_TRAINING, self::DEFAULT_MIN_POSTS_FOR_TRAINING));
        $this->min_posts_for_mature_model = (int) ($overrides['min_posts_for_mature_model'] ?? $get_opt(self::OPTION_MIN_POSTS_MATURE, self::DEFAULT_MIN_POSTS_FOR_MATURE_MODEL));
        $this->min_categories_for_training = (int) ($overrides['min_categories_for_training'] ?? $get_opt(self::OPTION_MIN_CATEGORIES_TRAINING, self::DEFAULT_MIN_CATEGORIES_FOR_TRAINING));
        $this->min_labelled_posts_for_training = (int) ($overrides['min_labelled_posts_for_training'] ?? $get_opt(self::OPTION_MIN_LABELLED_TRAINING, self::DEFAULT_MIN_LABELLED_POSTS_TRAINING));
        $this->min_labelled_posts_for_mature = (int) ($overrides['min_labelled_posts_for_mature'] ?? self::DEFAULT_MIN_LABELLED_POSTS_MATURE);
    }


    public function get_min_posts_for_training(): int {
        return max(1, $this->min_posts_for_training);
    }

    public function get_min_posts_for_mature_model(): int {
        return max(5, $this->min_posts_for_mature_model);
    }

    public function get_min_categories_for_training(): int {
        return max(2, $this->min_categories_for_training);
    }

    public function get_min_labelled_posts_for_training(): int {
        return max(1, $this->min_labelled_posts_for_training);
    }

    public function get_min_labelled_posts_for_mature(): int {
        return max(5, $this->min_labelled_posts_for_mature);
    }

    public function to_array(): array {
        return [
            'min_posts_for_training'          => $this->get_min_posts_for_training(),
            'min_posts_for_mature_model'      => $this->get_min_posts_for_mature_model(),
            'min_categories_for_training'     => $this->get_min_categories_for_training(),
            'min_labelled_posts_for_training' => $this->get_min_labelled_posts_for_training(),
            'min_labelled_posts_for_mature'   => $this->get_min_labelled_posts_for_mature(),
        ];
    }
}
