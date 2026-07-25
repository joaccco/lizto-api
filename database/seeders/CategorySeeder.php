<?php

namespace Database\Seeders;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'cerrajeria', 'name' => 'Cerrajería', 'icon' => 'lock'],
            ['slug' => 'electricidad', 'name' => 'Electricidad', 'icon' => 'zap'],
            ['slug' => 'plomeria', 'name' => 'Plomería', 'icon' => 'droplet'],
            ['slug' => 'fotografia', 'name' => 'Fotografía', 'icon' => 'camera'],
            ['slug' => 'abogacia', 'name' => 'Abogacía', 'icon' => 'scale'],
            ['slug' => 'contaduria', 'name' => 'Contaduría', 'icon' => 'calculator'],
            ['slug' => 'diseno', 'name' => 'Diseño', 'icon' => 'brush'],
            ['slug' => 'limpieza', 'name' => 'Limpieza', 'icon' => 'sparkles'],
        ];

        foreach ($categories as $index => $category) {
            CategoryModel::updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'parent_id' => null, 'is_active' => true, 'sort_order' => $index + 1]
            );
        }

        $parent = CategoryModel::where('slug', 'cerrajeria')->firstOrFail();
        $children = [
            ['slug' => 'cerrajeria-hogar', 'name' => 'Cerrajería del hogar'],
            ['slug' => 'cerrajeria-automotor', 'name' => 'Cerrajería automotor'],
            ['slug' => 'cerrajeria-comercial', 'name' => 'Cerrajería comercial'],
        ];

        foreach ($children as $index => $category) {
            CategoryModel::updateOrCreate(
                ['slug' => $category['slug']],
                [...$category, 'parent_id' => $parent->id, 'is_active' => true, 'sort_order' => $index + 1]
            );
        }
    }
}