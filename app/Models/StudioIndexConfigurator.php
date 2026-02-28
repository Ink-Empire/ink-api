<?php

namespace App\Models;

use Larelastic\Elastic\Constants\Datatypes;
use Larelastic\Elastic\Models\IndexConfigurator;
use Larelastic\Elastic\Traits\Migratable;

class StudioIndexConfigurator extends IndexConfigurator
{
    use Migratable;

    const DATE = [
        'type' => 'date',
        'format' => 'strict_date_optional_time||epoch_millis',
        "ignore_malformed" => true
    ];

    /** @var array */
    /** @var array */
    protected $settings = [
        'analysis' => [
            'analyzer' => [
                'search_text_analyzer' => [
                    'type' => 'custom',
                    'tokenizer' => 'whitespace',
                    'char_filter' => [
                        'parens_char_filter'
                    ],
                    'filter' => ['dont_split_on_numerics', 'lowercase']
                ],
                'quoted_analyzer' => [
                    'type' => 'custom',
                    'tokenizer' => 'quoted_tokenizer',
                    'filter' => ['remove_duplicates', 'lowercase', 'word_delimiter_graph'],
                ],
                'whitespace_analyzer' => [
                    'tokenizer' => 'whitespace',
                    'filter' => ['lowercase'],
                ]
            ],
            'filter' => [
                'dont_split_on_numerics' => [
                    'type' => 'word_delimiter',
                    'preserve_original' => true,
                    'generate_number_parts' => false,
                    'generate_word_parts' => false
                ],
                'filter_english_minimal' => [
                    "type" => "stemmer",
                    "name" => "minimal_english" //used to allow plurals discard == discards
                ]
            ],

            'char_filter' => [
                'my_mappings_char_filter' => [
                    'type' => "mapping",
                    'mappings' => [
                        ". => ",
                    ]
                ],
                'parens_char_filter' => [
                    'type' => "mapping",
                    'mappings' => [
                        "( => ",
                        ") => ",
                    ]
                ],
                'synonyms_char_filter' => [
                    'type' => "mapping",
                    'mappings' => [
                        ". => ",
                        ", => ", //remove , and .
                        "- =>\\u0020", //replace dash with empty space
                    ]
                ]
            ],

            'tokenizer' => [
                'quoted_tokenizer' => [
                    'type' => 'pattern',
                    "pattern" => '"((?=>\\"|[^"]|\\")*)"',
                ],
            ],

            'normalizer' => [
                'lowercase_normalizer' => [
                    'type' => 'custom',
                    'char_filter' => [],
                    'filter' => ['lowercase'],
                ]
            ],
        ],
    ];

    const STYLE = [
        'type' => 'nested',
        'properties' => [
            'id' => Datatypes::INTEGER,
            'name' => Datatypes::KEYWORD,
        ],
    ];

    const IMAGE = [
        'type' => 'nested',
        'properties' => [
            'uri' => Datatypes::KEYWORD,
            'is_primary' => Datatypes::BOOLEAN,
        ],
    ];

    const STUDIO = [
        'type' => 'nested',
        'properties' => [
            'name' => Datatypes::KEYWORD,
            'about' => Datatypes::TEXT,
            'location' => Datatypes::KEYWORD,
            'location_lat_long' => Datatypes::GEO_POINT,
            'email' => Datatypes::KEYWORD,
            'phone' => Datatypes::KEYWORD,
            'image' => self::IMAGE,
        ],
    ];

    const SETTINGS = [
        'type' => 'nested',
        'properties' => [
            'books_open' => Datatypes::BOOLEAN,
            'accepts_walk_ins' => Datatypes::BOOLEAN,
            'accepts_consultations' => Datatypes::BOOLEAN,
            'accepts_appointments' => Datatypes::BOOLEAN,
            'accepts_deposits' => Datatypes::BOOLEAN,
        ]
    ];

    const SOCIAL_MEDIA_LINK = [
        'type' => 'nested',
        'properties' => [
            'platform' => Datatypes::KEYWORD,
            'username' => Datatypes::KEYWORD,
            'url' => Datatypes::KEYWORD,
        ]
    ];

    /** @var array */
    protected $mappings = [
        'properties' => [
            'id' => Datatypes::INTEGER,
            'about' => Datatypes::TEXT,
            'email' => Datatypes::KEYWORD,
            'slug' => Datatypes::KEYWORD,
            'location' => Datatypes::KEYWORD,
            'location_lat_long' => Datatypes::GEO_POINT,
            'name' => Datatypes::TEXT,
            'phone' => Datatypes::KEYWORD,
            'type' => Datatypes::KEYWORD,
            'is_featured' => Datatypes::BOOLEAN,
            'is_demo' => Datatypes::BOOLEAN,
            'is_claimed' => Datatypes::BOOLEAN,
            'saved_count' => Datatypes::INTEGER,
            'created_at' => self::DATE,
            'rating' => Datatypes::FLOAT,
            'styles' => self::STYLE,
            'primary_image' => self::IMAGE,
        ]
    ];

    public function getName()
    {
        return config('elastic.client.studios_index', 'studios');
    }
}
