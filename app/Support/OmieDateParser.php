<?php

declare(strict_types=1);

namespace BIomie\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * AGENDA DE ORÇAMENTOS — Fase 2 (metade 1).
 *
 * Parser ESTRITO e determinístico de datas/horas do payload Omie de pedido.
 * Puro: sem I/O, sem banco, sem chamada Omie. Usado pelos normalizadores para
 * preencher data_inclusao_omie / data_alteracao_omie / data_previsao_faturamento.
 *
 * Diferente de Support::formatDate (lenient — createFromFormat sem checar
 * getLastErrors + fallback strtotime), este parser REJEITA silenciosamente:
 *   ""  ·  "00/00/0000"  ·  "00/00/0000 00:00:00"  ·  datas impossíveis (overflow).
 *
 * Combina data+hora no fuso da aplicação (America/Fortaleza) e grava wall-clock
 * ('Y-m-d H:i:s' / 'Y-m-d') SEM conversão para UTC — mesma convenção dos demais
 * campos DATE do canônico (data_emissao etc.).
 */
final class OmieDateParser
{
    private const TZ = 'America/Fortaleza';

    /** Formatos de data aceitos (Omie usa d/m/Y; Y-m-d por robustez). */
    private const DATE_FORMATS = ['d/m/Y', 'Y-m-d'];

    /** Formatos de hora aceitos (hInc/hAlt = H:i:s; H:i por robustez). */
    private const TIME_FORMATS = ['H:i:s', 'H:i'];

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    /**
     * Data estrita → 'Y-m-d' ou null.
     */
    public static function parseDate(?string $value): ?string
    {
        return self::strictDate($value)?->format('Y-m-d');
    }

    /**
     * Data+hora estrita → 'Y-m-d H:i:s' (America/Fortaleza) ou null.
     * Política: data válida + hora ausente/ inválida → meia-noite (00:00:00),
     * preservando a data conhecida (recomendação do checkpoint).
     */
    public static function parseDateTime(?string $date, ?string $time = null): ?string
    {
        $day = self::strictDate($date);
        if ($day === null) {
            return null;
        }

        $clock = self::strictTime($time) ?? '00:00:00';

        return $day->format('Y-m-d') . ' ' . $clock;
    }

    private static function strictDate(?string $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // Rejeita rapidamente zero-dates ("00/00/0000", "0000-00-00", com/sem hora).
        if (preg_match('#^0{1,4}[/-]0{1,2}[/-]0{1,4}#', $value) === 1) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value, self::timezone());
            if ($dt instanceof DateTimeImmutable && self::parsedClean() && (int) $dt->format('Y') > 0) {
                return $dt;
            }
        }

        return null;
    }

    private static function strictTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (self::TIME_FORMATS as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value, self::timezone());
            if ($dt instanceof DateTimeImmutable && self::parsedClean()) {
                return $dt->format('H:i:s');
            }
        }

        return null;
    }

    /**
     * True somente quando o último createFromFormat não gerou warnings (overflow,
     * ex.: dia 00/mês 13) nem errors (trailing data, formato inválido).
     * getLastErrors() retorna false quando não há warning nem error (PHP 8.2+).
     */
    private static function parsedClean(): bool
    {
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors === false) {
            return true;
        }

        return $errors['warning_count'] === 0
            && $errors['error_count'] === 0;
    }
}
