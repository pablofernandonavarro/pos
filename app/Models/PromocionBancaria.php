<?php

namespace App\Models;

use App\Support\Dinero;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Copia local de una promoción bancaria del Manager. Se reemplaza entera en cada
 * sincronización; acá solo se consulta y se aplica.
 */
class PromocionBancaria extends Model
{
    protected $table = 'promociones_bancarias';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'nombre',
        'banco',
        'medios',
        'tarjetas',
        'dias_semana',
        'vigencia_desde',
        'vigencia_hasta',
        'modalidad',
        'porcentaje',
        'tope',
        'monto_minimo',
        'cuotas_sin_interes',
    ];

    protected function casts(): array
    {
        return [
            'medios' => 'array',
            'tarjetas' => 'array',
            'dias_semana' => 'array',
            'vigencia_desde' => 'date',
            'vigencia_hasta' => 'date',
            'porcentaje' => 'decimal:2',
            'tope' => 'decimal:2',
            'monto_minimo' => 'decimal:2',
            'cuotas_sin_interes' => 'integer',
        ];
    }

    /**
     * Si la promoción se puede usar para cobrar $montoCentavos con este medio, tarjeta
     * y banco en esta fecha. El banco se compara sin mayúsculas ni espacios de más: lo
     * tipea una persona en el Manager y otra elige en la caja.
     */
    public function aplicaA(string $medio, ?string $tarjeta, ?string $banco, int $montoCentavos, ?Carbon $fecha = null): bool
    {
        $fecha ??= now();

        if (! in_array($medio, $this->medios ?? [], true)) {
            return false;
        }

        if ($this->tarjetas && ! in_array($tarjeta, $this->tarjetas, true)) {
            return false;
        }

        if ($this->banco !== null && self::normalizarBanco($this->banco) !== self::normalizarBanco((string) $banco)) {
            return false;
        }

        if ($this->dias_semana && ! in_array($fecha->dayOfWeekIso, array_map('intval', $this->dias_semana), true)) {
            return false;
        }

        // copy(): Carbon es mutable y startOfDay() alteraría el atributo del modelo.
        if ($this->vigencia_desde && $fecha->lt($this->vigencia_desde->copy()->startOfDay())) {
            return false;
        }

        if ($this->vigencia_hasta && $fecha->gt($this->vigencia_hasta->copy()->endOfDay())) {
            return false;
        }

        return $this->monto_minimo === null || $montoCentavos >= Dinero::centavos($this->monto_minimo);
    }

    /**
     * Descuento en caja, en centavos. Un reintegro del banco no descuenta nada acá: se
     * cobra completo y el banco devuelve después.
     */
    public function descuentoPara(int $montoCentavos): int
    {
        if ($this->modalidad !== 'descuento' || (float) $this->porcentaje <= 0) {
            return 0;
        }

        $descuento = (int) round($montoCentavos * ((float) $this->porcentaje) / 100);

        if ($this->tope !== null) {
            $descuento = min($descuento, Dinero::centavos($this->tope));
        }

        return min($descuento, $montoCentavos);
    }

    /** Texto corto para mostrar en la caja. */
    public function beneficio(): string
    {
        $partes = [];

        if ((float) $this->porcentaje > 0) {
            $pct = rtrim(rtrim(number_format((float) $this->porcentaje, 2, ',', ''), '0'), ',');
            $partes[] = $this->modalidad === 'descuento' ? "{$pct}% off" : "{$pct}% de reintegro";

            if ($this->tope !== null) {
                $partes[] = 'tope '.Dinero::formato($this->tope);
            }
        }

        if ($this->cuotas_sin_interes) {
            $partes[] = "{$this->cuotas_sin_interes} cuotas s/interés";
        }

        return implode(' · ', $partes);
    }

    public static function normalizarBanco(string $banco): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($banco)));
    }
}
