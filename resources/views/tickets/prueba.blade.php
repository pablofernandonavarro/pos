<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: 80mm auto; margin: 2mm; }
        body { font-family: 'Courier New', monospace; font-size: 12px; width: 72mm; margin: 0 auto; text-align: center; }
        .sep { border-top: 1px dashed #000; margin: 6px 0; }
    </style>
</head>
<body>
    <strong>{{ $comercio['nombre'] }}</strong>
    <div class="sep"></div>
    PRUEBA DE IMPRESIÓN #1
    <div>{{ now()->timezone(config('pos.zona_horaria'))->format('d/m/Y H:i') }}</div>
    <div class="sep"></div>
    Si lee esto, la impresora está lista.
    <div class="sep"></div>
    <div>áéíóú ñ $ 1.234,56</div>
</body>
</html>
