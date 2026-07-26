<?php

declare(strict_types=1);

return [
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('framework/cache/dompdf-fonts'),
        'font_cache' => storage_path('framework/cache/dompdf-fonts'),
        'temp_dir' => storage_path('framework/cache'),

        // PDF templates are self-contained and may only resolve files from this narrow tree.
        'chroot' => [resource_path('views/documents')],
        'allowed_protocols' => [
            'file://' => ['rules' => []],
        ],
        'allowed_remote_hosts' => [],
        'artifactPathValidation' => null,
        'log_output_file' => null,

        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'enable_html5_parser' => true,
        'enable_font_subsetting' => true,

        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'DejaVu Sans',
        'dpi' => 96,
        'font_height_ratio' => 1.05,
    ],
];
