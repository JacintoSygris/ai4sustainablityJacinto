<?php

return [
    // On by default since 2026-11-17: the taxonomy package and Arelle are provisioned
    // automatically (see external_taxonomy below and ArelleCommandLocator).
    'package_ui_enabled' => filter_var(env('IXBRL_PACKAGE_UI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'external_taxonomy' => [
        // Download the published EFRAG package into the external asset root when no
        // operator-managed manifest is configured. The package stays outside the
        // application, storage and repository trees.
        'autoprovision' => env('ESRS_EXTERNAL_TAXONOMY_AUTOPROVISION', true),
        'profile_id' => 'esrs-2023-preparatory-v1',
        'download_url' => env('ESRS_EXTERNAL_TAXONOMY_URL', 'https://xbrl.efrag.org/downloads/ESRS-Set1-XBRL-Taxonomy.zip'),
        'checksum' => env('ESRS_EXTERNAL_TAXONOMY_SHA256', 'f9dab98514dbb27b53f6bf94cb1980920e6029d5d21963677954275c217850b3'),
        'package_filename' => 'ESRS-Set1-XBRL-Taxonomy.zip',
        'max_bytes' => 50 * 1024 * 1024,
        'timeout_seconds' => 120,
    ],
    'taxonomy_profile' => env('IXBRL_TAXONOMY_PROFILE', 'esrs_set1_2023-12-22'),
    'profiles' => [
        'esrs_set1_2023-12-22' => [
            'status' => 'final',
            'enabled' => true,
            'namespace' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22',
            'entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
            'core_schema' => 'xbrl.efrag.org/taxonomy/esrs/2023-12-22/common/esrs_cor.xsd',
            'mapping_profile_id' => 'esrs-2023-preparatory-v1',
            'concept_mapping' => 'data/atomizer_xbrl_concepts_v1.json',
        ],
        'esrs_2026_draft_2026-08-30' => [
            'status' => 'provisional',
            'enabled' => env('IXBRL_2026_DRAFT_ENABLED', false),
            'namespace' => 'https://xbrl.efrag.org/taxonomy/esrs/2026-08-30',
            'entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2026-08-30/esrs.xsd',
            'core_schema' => null,
            'mapping_profile_id' => null,
            'concept_mapping' => null,
        ],
    ],
];
