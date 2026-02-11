<?php

/**
 * PHP CS Fixer 代码风格配置
 * 
 * 运行命令:
 *   检查: php-cs-fixer fix --dry-run --diff
 *   修复: php-cs-fixer fix
 * 
 * @see https://cs.symfony.com/doc/rules/index.html
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/backend/core',
        __DIR__ . '/backend/api',
        __DIR__ . '/backend/cron',
        __DIR__ . '/public',
    ])
    ->exclude([
        'vendor',
        'node_modules',
        'frontend/dist',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // ============================================
        // PSR-12 基础规范
        // ============================================
        '@PSR12' => true,
        '@PHP82Migration' => true,
        
        // ============================================
        // 数组格式
        // ============================================
        'array_syntax' => ['syntax' => 'short'],
        'array_indentation' => true,
        'trim_array_spaces' => true,
        'no_trailing_comma_in_singleline' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        
        // ============================================
        // 导入/命名空间
        // ============================================
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'no_unused_imports' => true,
        'single_import_per_statement' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        
        // ============================================
        // 空格与缩进
        // ============================================
        'binary_operator_spaces' => [
            'default' => 'single_space',
            'operators' => [
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'concat_space' => ['spacing' => 'one'],
        'not_operator_with_successor_space' => true,
        'object_operator_without_whitespace' => true,
        'type_declaration_spaces' => true,
        
        // ============================================
        // 花括号与结构
        // ============================================
        'braces_position' => [
            'functions_opening_brace' => 'same_line',
            'classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
        ],
        'control_structure_braces' => true,
        'control_structure_continuation_position' => [
            'position' => 'same_line',
        ],
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra',
                'throw',
                'use',
                'return',
                'curly_brace_block',
            ],
        ],
        'single_blank_line_at_eof' => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try', 'if', 'switch', 'foreach', 'for', 'while'],
        ],
        
        // ============================================
        // 注释格式
        // ============================================
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'multiline_comment_opening_closing' => true,
        'no_empty_comment' => true,
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_indent' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        
        // ============================================
        // 类型声明
        // ============================================
        'declare_strict_types' => false, // 渐进式启用
        'void_return' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'nullable_type_declaration_for_default_null_value' => true,
        
        // ============================================
        // 字符串
        // ============================================
        'single_quote' => true,
        'explicit_string_variable' => true,
        'simple_to_complex_string_variable' => true,
        
        // ============================================
        // 类与方法
        // ============================================
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'one',
                'method' => 'one',
                'property' => 'one',
            ],
        ],
        'class_definition' => [
            'single_line' => true,
            'single_item_single_line' => true,
        ],
        'no_null_property_initialization' => true,
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        
        // ============================================
        // 安全与最佳实践
        // ============================================
        'no_alias_functions' => true,
        'no_mixed_echo_print' => ['use' => 'echo'],
        'cast_spaces' => ['space' => 'single'],
        'lowercase_cast' => true,
        'short_scalar_cast' => true,
        'no_empty_statement' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'simplified_null_return' => true,
        'ternary_operator_spaces' => true,
        'ternary_to_null_coalescing' => true,
        
        // ============================================
        // 其他
        // ============================================
        'encoding' => true,
        'full_opening_tag' => true,
        'no_closing_tag' => true,
        'line_ending' => true,
        'compact_nullable_type_declaration' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
