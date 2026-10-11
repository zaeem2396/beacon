<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->name('*.php')
    ->notName('*.blade.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP82Migration' => true,
        '@PHP80Migration:risky' => true,
        '@PSR12' => true,

        // Imports
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],

        // Declare
        'declare_strict_types' => true,

        // Arrays
        'array_syntax' => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'normalize_index_brace' => true,

        // Strings
        'single_quote' => true,
        'explicit_string_variable' => true,

        // Functions / methods
        'function_typehint_space' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'void_return' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // Classes
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        'self_accessor' => true,
        'no_null_property_initialization' => true,

        // Whitespace / blank lines
        'blank_line_before_statement' => [
            'statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try'],
        ],
        'method_chaining_indentation' => true,

        // PHPDoc
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'phpdoc_separation' => true,
        'no_empty_phpdoc' => true,
        'no_superfluous_phpdoc_tags' => ['remove_inheritdoc' => true],
        'phpdoc_no_useless_inheritdoc' => true,

        // Misc
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'not_operator_with_successor_space' => true,
        'object_operator_without_whitespace' => true,
        'standardize_not_equals' => true,
    ])
    ->setFinder($finder);
