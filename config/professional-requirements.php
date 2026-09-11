<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Requisitos Profesionales por Categoría (ADR-011 / KYC)
    |--------------------------------------------------------------------------
    | Define qué documentación y validaciones se exigen para que un profesional
    | de cada rubro pueda obtener la verificación aprobada en professional_mvus.
    | Las subcategorías heredan las reglas de su categoría padre.
    | Si una categoría no tiene reglas definidas, el sistema FALLA CERRADO.
    |
    */
    'categories' => [
        'cerrajeria' => [
            'identity_required' => true,
            'antecedentes_required' => true,
            'matrícula_required' => false,
            'skills_verification_required' => false,
            'min_experience_years' => 0,
        ],
        'electricidad' => [
            'identity_required' => true,
            'antecedentes_required' => true,
            'matrícula_required' => true,
            'skills_verification_required' => true,
            'min_experience_years' => 2,
        ],
        'plomeria' => [
            'identity_required' => true,
            'antecedentes_required' => true,
            'matrícula_required' => false,
            'skills_verification_required' => false,
            'min_experience_years' => 1,
        ],
        'abogacia' => [
            'identity_required' => true,
            'antecedentes_required' => true,
            'matrícula_required' => true,
            'skills_verification_required' => false,
            'min_experience_years' => 0,
        ],
        'contaduria' => [
            'identity_required' => true,
            'antecedentes_required' => true,
            'matrícula_required' => true,
            'skills_verification_required' => false,
            'min_experience_years' => 0,
        ],
        'fotografia' => [
            'identity_required' => true,
            'antecedentes_required' => true,
            'matrícula_required' => false,
            'skills_verification_required' => false,
            'min_experience_years' => 0,
        ],
        'limpieza' => [
            'identity_required' => true,
            'antecedentes_required' => true,
            'matrícula_required' => false,
            'skills_verification_required' => false,
            'min_experience_years' => 0,
        ],
        'diseno' => [
            'identity_required' => true,
            'antecedentes_required' => false,
            'matrícula_required' => false,
            'skills_verification_required' => false,
            'min_experience_years' => 0,
        ],
    ],
];
