<?php

namespace App\Models;

use Larelastic\Elastic\Constants\Datatypes;
use Larelastic\Elastic\Models\IndexConfigurator;
use Larelastic\Elastic\Traits\Migratable;

class TattooIndexConfigurator extends IndexConfigurator
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
        "number_of_shards" => 3,
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
                'tags_char_filter' => [
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
                ],
                'tags_normalizer' => [
                    'type' => 'custom',
                    'char_filter' => 'tags_char_filter',
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

    const SUBJECT = [
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
        ],
    ];

    const ARTIST = [
        'type' => 'nested',
        'properties' => [
            'id' => Datatypes::INTEGER,
            'email' => Datatypes::KEYWORD,
            'image' => self::IMAGE,
            'location' => Datatypes::KEYWORD,
            'location_lat_long' => Datatypes::GEO_POINT,
            'name' => Datatypes::KEYWORD,
            'studio' => Datatypes::KEYWORD,
            'type' => Datatypes::KEYWORD,
            'styles' => self::STYLE,
        ],
    ];


    /** @var array */
    protected $mappings = [
        'properties' => [
            'id' => Datatypes::INTEGER,
            'title' => Datatypes::KEYWORD,
            'description' => Datatypes::TEXT,
            'placement' => Datatypes::KEYWORD,
            'artist' => self::ARTIST,
            'studio' => self::STUDIO,
            'primary_style' => Datatypes::KEYWORD,
            'primary_subject' => Datatypes::KEYWORD,
            'primary_image' => self::IMAGE,
            'images' => self::IMAGE,
            'styles' => self::STYLE,

            'tags' => [
                'type' => 'keyword',
                'normalizer' => 'tags_normalizer'
            ],
        ]
    ];

    public function getName()
    {
        $name = config('elastic.client.index');

        return $name;
    }
}
