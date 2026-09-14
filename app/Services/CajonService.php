<?php

namespace App\Services;

use App\Contracts\CajonDinero;
use App\Exceptions\CajaException;
use App\Models\AperturaCajon;
use App\Models\Configuracion;
use App\Models\TurnoCaja;

/**
 * Cuándo se abre el cajón. Nunca frena una venta: si no abre, se avisa y listo.
 */
class CajonService
{
    public function __construct(
        private readonly CajonDinero $cajon,
    ) {}

    public function habilitado(): bool
    {
        return (bool) Configuracion::get('cajon_habilitado', false) && $this->cajon->disponible();
    }

    /**
     * Después de una venta, cobro o devolución: abre solo si hubo efectivo y está configurado.
     *
     * @return string|null null si abrió o no correspondía; si no, el aviso.
     */
    public function abrirPorEfectivo(bool $huboEfectivo): ?string
    {
        if (! $huboEfectivo || ! $this->habilitado() || ! Configuracion::get('cajon_automatico', true)) {
            return null;
        }

        return $this->cajon->abrir((string) Configuracion::get('impresora_ticket', ''));
    }

    /**
     * Apertura manual: queda registrada con el motivo (sale en el informe X/Z) aunque el
     * pulso falle, porque el cajero la pidió.
     */
    public function abrirManual(TurnoCaja $turno, string $motivo): ?string
    {
        $motivo = trim($motivo);

        if (! $this->habilitado()) {
            throw new CajaException('El cajón no está habilitado en esta caja (Ajustes → Cajón de dinero).');
        }

        if (! $turno->estaAbierto()) {
            throw new CajaException('La caja está cerrada.');
        }

        if ($motivo === '') {
            throw new CajaException('Indicá por qué se abre el cajón sin venta.');
        }

        $error = $this->cajon->abrir((string) Configuracion::get('impresora_ticket', ''));

        AperturaCajon::create([
            'turno_caja_id' => $turno->id,
            'cajero' => $turno->cajero,
            'motivo' => mb_substr($motivo, 0, 200),
            'abrio' => $error === null,
        ]);

        return $error;
    }
}
