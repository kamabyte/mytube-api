<?php

namespace App\Support;

/**
 * Автосубтитры YouTube — «бегущие» (roll-up): каждая реплика повторяет
 * прошлую строку сверху и добавляет новую снизу, а между репликами стоят
 * 10-мс вставки с пустой нижней строкой. На YouTube новая строка проявляется
 * по словам, но пословные тайминги теряются при вшивании в mov_text, и в
 * браузере такие субтитры дёргаются: две строки → одна → две.
 *
 * Здесь вставки выбрасываются, паузы между репликами закрываются, а в новую
 * строку возвращаются метки времени слов WebVTT (`слово <00:00:01.250>слово`):
 * время реплики делится пропорционально длине слов, плеер проявляет их по
 * одному. Обычные субтитры возвращаются как есть.
 */
class RollUpCaptions
{
    /** Реплики короче — служебные вставки между строками. */
    private const float BLIP_SECONDS = 0.05;

    /** Паузы короче закрываются, чтобы плашка не мигала. */
    private const float GAP_SECONDS = 0.1;

    /** Доля реплик, продолжающих прошлую строку, с которой дорожка считается бегущей. */
    private const float ROLL_UP_SHARE = 0.5;

    public static function smooth(string $vtt): string
    {
        $cues = array_values(array_filter(self::parse($vtt), fn (array $cue) => $cue['end'] - $cue['start'] >= self::BLIP_SECONDS));

        if (! self::isRollUp($cues)) {
            return $vtt;
        }

        $blocks = [];

        foreach ($cues as $index => $cue) {
            $next = $cues[$index + 1]['start'] ?? null;
            // Закрываем короткие паузы и обрезаем наложения на следующую реплику.
            $end = $next !== null && $next - $cue['end'] < self::GAP_SECONDS ? $next : $cue['end'];
            $lines = [...array_slice($cue['lines'], 0, -1), self::timeWords(end($cue['lines']), $cue['start'], $end)];

            $blocks[] = self::timestamp($cue['start']).' --> '.self::timestamp($end)."\n".implode("\n", $lines);
        }

        return "WEBVTT\n\n".implode("\n\n", $blocks)."\n";
    }

    /** Строка с метками времени перед каждым словом, кроме первого. */
    private static function timeWords(string $line, float $start, float $end): string
    {
        $words = preg_split('/\s+/u', $line);
        $total = array_sum(array_map(fn (string $word) => mb_strlen($word) + 1, $words));
        $shown = 0;
        $timed = [];

        foreach ($words as $index => $word) {
            $at = $start + ($end - $start) * $shown / $total;
            $timed[] = $index === 0 ? $word : '<'.self::timestamp($at).'>'.$word;
            $shown += mb_strlen($word) + 1;
        }

        return implode(' ', $timed);
    }

    /**
     * @param  list<array{start: float, end: float, lines: list<string>}>  $cues
     */
    private static function isRollUp(array $cues): bool
    {
        $continued = 0;

        foreach ($cues as $index => $cue) {
            $previous = $cues[$index - 1]['lines'] ?? null;

            if ($previous !== null && count($cue['lines']) > 1 && $cue['lines'][0] === end($previous)) {
                $continued++;
            }
        }

        return count($cues) > 0 && $continued / count($cues) >= self::ROLL_UP_SHARE;
    }

    /**
     * Реплики WebVTT от ffmpeg. Текст собирается до следующей строки времени,
     * а не до пустой строки: у первой реплики после паузы ffmpeg пишет пустую
     * верхнюю строку, и по правилам WebVTT её текст бы потерялся.
     *
     * @return list<array{start: float, end: float, lines: list<string>}>
     */
    private static function parse(string $vtt): array
    {
        $cues = [];
        $current = null;

        foreach (explode("\n", str_replace("\r", '', $vtt)) as $line) {
            if (str_contains($line, '-->')) {
                if ($current !== null && $current['lines'] !== []) {
                    $cues[] = $current;
                }

                [$start, $end] = array_map(
                    fn (string $part) => self::seconds(preg_split('/\s+/', trim($part))[0]),
                    explode('-->', $line, 2),
                );
                $current = ['start' => $start, 'end' => $end, 'lines' => []];
            } elseif ($current !== null && trim($line) !== '') {
                $current['lines'][] = trim($line);
            }
        }

        if ($current !== null && $current['lines'] !== []) {
            $cues[] = $current;
        }

        return $cues;
    }

    private static function seconds(string $timestamp): float
    {
        return array_reduce(explode(':', $timestamp), fn (float $total, string $part) => $total * 60 + (float) $part, 0.0);
    }

    private static function timestamp(float $seconds): string
    {
        $milliseconds = (int) round($seconds * 1000);

        return sprintf(
            '%02d:%02d:%02d.%03d',
            intdiv($milliseconds, 3_600_000),
            intdiv($milliseconds, 60_000) % 60,
            intdiv($milliseconds, 1000) % 60,
            $milliseconds % 1000,
        );
    }
}
