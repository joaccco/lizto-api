<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical Category Vocabulary & Synonyms (T4 / AG-API+WEB-03-v2)
    |--------------------------------------------------------------------------
    | Define la fuente única de verdad para el vocabulario de categorías.
    | Todos los sinónimos y alias usados por clientes, frontend o integraciones
    | resuelven de forma canónica contra esta configuración única.
    */
    'canonical' => [
        'cerrajeria' => [
            'name' => 'Cerrajería',
            'synonyms' => ['cerrajero', 'cerrajeros', 'cerrajería', 'cerrajeria', 'llaves', 'cerraduras'],
        ],
        'electricidad' => [
            'name' => 'Electricidad',
            'synonyms' => ['electricista', 'electricistas', 'electricidad', 'electrico', 'eléctrico'],
        ],
        'plomeria' => [
            'name' => 'Plomería',
            'synonyms' => ['plomero', 'plomeros', 'plomería', 'plomeria', 'gasista', 'gasistas', 'fontanero', 'fontaneros'],
        ],
        'abogacia' => [
            'name' => 'Abogacía',
            'synonyms' => ['abogado', 'abogados', 'abogada', 'abogadas', 'abogacia', 'abogacía', 'legal', 'legales'],
        ],
        'contaduria' => [
            'name' => 'Contaduría',
            'synonyms' => ['contador', 'contadores', 'contadora', 'contadoras', 'contaduria', 'contaduría', 'contable', 'contables'],
        ],
        'fotografia' => [
            'name' => 'Fotografía',
            'synonyms' => ['fotografo', 'fotógrafos', 'fotógrafo', 'fotógrafa', 'fotógrafas', 'fotografia', 'fotografía'],
        ],
        'diseno' => [
            'name' => 'Diseño',
            'synonyms' => ['disenador', 'diseñador', 'diseñadores', 'diseñadora', 'diseño', 'diseno'],
        ],
        'limpieza' => [
            'name' => 'Limpieza',
            'synonyms' => ['limpiador', 'limpiadores', 'limpieza', 'maestranza', 'empleada-domestica'],
        ],
        'cerrajeria-hogar' => [
            'name' => 'Cerrajería del hogar',
            'synonyms' => ['cerrajeria-hogar', 'cerrajero-hogar', 'cerrajería-hogar'],
        ],
        'cerrajeria-automotor' => [
            'name' => 'Cerrajería automotor',
            'synonyms' => ['cerrajeria-automotor', 'cerrajero-automotor', 'cerrajería-automotor'],
        ],
        'cerrajeria-comercial' => [
            'name' => 'Cerrajería comercial',
            'synonyms' => ['cerrajeria-comercial', 'cerrajero-comercial', 'cerrajería-comercial'],
        ],
    ],
];
