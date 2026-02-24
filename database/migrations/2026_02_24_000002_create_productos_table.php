<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('codigo_interno')->nullable()->index();
            $table->string('codigo_barras')->nullable()->index();
            $table->text('busqueda')->nullable();
            $table->decimal('precio', 10, 3)->default(0);
            $table->decimal('costo', 10, 3)->default(0);
            $table->integer('stock')->default(0);
            $table->integer('stock_critico')->default(0);
            $table->string('imagen_url')->nullable();
            $table->text('descripcion_web')->nullable();
            $table->string('marca')->nullable();
            $table->string('color')->nullable();
            $table->string('n_talle')->nullable();
            $table->string('genero')->nullable();
            $table->string('n_grupo')->nullable();
            $table->string('n_subgrupo')->nullable();
            $table->string('n_temporada')->nullable();
            $table->string('product_type')->default('simple');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('es_vendible')->default(true);
            $table->boolean('activo')->default(true);
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();

            $table->index(['es_vendible', 'activo']);
            $table->index('parent_id');
        });

        // Crear índice de texto completo para búsqueda
        DB::statement('CREATE VIRTUAL TABLE productos_fts USING fts5(id, busqueda, content=productos, content_rowid=id)');

        // Trigger para mantener sincronizado el índice FTS
        DB::statement('
            CREATE TRIGGER productos_ai AFTER INSERT ON productos BEGIN
                INSERT INTO productos_fts(rowid, id, busqueda) VALUES (new.id, new.id, new.busqueda);
            END
        ');

        DB::statement('
            CREATE TRIGGER productos_ad AFTER DELETE ON productos BEGIN
                INSERT INTO productos_fts(productos_fts, rowid, id, busqueda) VALUES(\'delete\', old.id, old.id, old.busqueda);
            END
        ');

        DB::statement('
            CREATE TRIGGER productos_au AFTER UPDATE ON productos BEGIN
                INSERT INTO productos_fts(productos_fts, rowid, id, busqueda) VALUES(\'delete\', old.id, old.id, old.busqueda);
                INSERT INTO productos_fts(rowid, id, busqueda) VALUES (new.id, new.id, new.busqueda);
            END
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS productos_au');
        DB::statement('DROP TRIGGER IF EXISTS productos_ad');
        DB::statement('DROP TRIGGER IF EXISTS productos_ai');
        DB::statement('DROP TABLE IF EXISTS productos_fts');
        Schema::dropIfExists('productos');
    }
};
