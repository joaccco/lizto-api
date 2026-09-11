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
            'keywords' => ['cerrajero', 'cerradura', 'llave', 'candado', 'apertura', 'afuera', 'puerta', 'traba'],
        ],
        'electricidad' => [
            'name' => 'Electricidad',
            'synonyms' => ['electricista', 'electricistas', 'electricidad', 'electrico', 'eléctrico'],
            'keywords' => ['electricista', 'luz', 'cable', 'enchufe', 'cortocircuito', 'tablero', 'térmica', 'cables', 'chispas'],
        ],
        'plomeria' => [
            'name' => 'Plomería',
            'synonyms' => ['plomero', 'plomeros', 'plomería', 'plomeria', 'gasista', 'gasistas', 'fontanero', 'fontaneros'],
            'keywords' => ['plomero', 'caño', 'cano', 'pérdida', 'perdida', 'agua', 'canilla', 'fuga', 'gotera', 'inodoro', 'destape'],
        ],
        'abogacia' => [
            'name' => 'Abogacía',
            'synonyms' => ['abogado', 'abogados', 'abogada', 'abogadas', 'abogacia', 'abogacía', 'legal', 'legales'],
            'keywords' => ['abogado', 'abogada', 'legal', 'contrato', 'juicio', 'despido', 'sucesion'],
        ],
        'contaduria' => [
            'name' => 'Contaduría',
            'synonyms' => ['contador', 'contadores', 'contadora', 'contadoras', 'contaduria', 'contaduría', 'contable', 'contables'],
            'keywords' => ['contador', 'contadora', 'impuestos', 'factura', 'contabilidad', 'monotributo', 'balance', 'afip'],
        ],
        'fotografia' => [
            'name' => 'Fotografía',
            'synonyms' => ['fotografo', 'fotógrafos', 'fotógrafo', 'fotógrafa', 'fotógrafas', 'fotografia', 'fotografía'],
            'keywords' => ['fotógrafo', 'fotografo', 'fotógrafa', 'fotografía', 'fotografia', 'sesión', 'sesion', 'fotos', 'foto', 'boda', 'evento', 'retrato'],
        ],
        'diseno' => [
            'name' => 'Diseño',
            'synonyms' => ['disenador', 'diseñador', 'diseñadores', 'diseñadora', 'diseño', 'diseno'],
            'keywords' => ['diseñador', 'disenador', 'diseño', 'diseno', 'logo', 'branding', 'flyer', 'web', 'ui'],
        ],
        'limpieza' => [
            'name' => 'Limpieza',
            'synonyms' => ['limpiador', 'limpiadores', 'limpieza', 'maestranza', 'empleada-domestica'],
            'keywords' => ['limpieza', 'mucama', 'ordenar', 'limpiar', 'oficina', 'mudanza', 'profunda'],
        ],
        'cerrajeria-hogar' => [
            'name' => 'Cerrajería del hogar',
            'synonyms' => ['cerrajeria-hogar', 'cerrajero-hogar', 'cerrajería-hogar'],
            'keywords' => ['cerrajeria-hogar', 'cerrajero-hogar', 'casa', 'departamento'],
        ],
        'cerrajeria-automotor' => [
            'name' => 'Cerrajería automotor',
            'synonyms' => ['cerrajeria-automotor', 'cerrajero-automotor', 'cerrajería-automotor'],
            'keywords' => ['cerrajeria-automotor', 'cerrajero-automotor', 'auto', 'coche', 'vehiculo'],
        ],
        'cerrajeria-comercial' => [
            'name' => 'Cerrajería comercial',
            'synonyms' => ['cerrajeria-comercial', 'cerrajero-comercial', 'cerrajería-comercial'],
            'keywords' => ['cerrajeria-comercial', 'cerrajero-comercial', 'local', 'negocio', 'persiana'],
        ],
    ],
];
