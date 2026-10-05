<?php

use App\Support\RollUpCaptions;

it('turns YouTube roll-up captions into steady cues with timed words', function (): void {
    // Так ffmpeg отдаёт вшитые автосубтитры YouTube: пустая первая строка,
    // повтор прошлой строки и 10-мс вставки с пустой нижней строкой.
    $vtt = <<<'VTT'
        WEBVTT

        00:00.120 --> 00:01.589

        One two

        00:01.589 --> 00:01.599
        One two


        00:01.599 --> 00:02.909
        One two
        three four

        00:02.909 --> 00:02.919
        three four


        00:02.919 --> 00:04.000
        three four
        five
        VTT;

    expect(RollUpCaptions::smooth($vtt))->toBe(<<<'VTT'
        WEBVTT

        00:00:00.120 --> 00:00:01.599
        One <00:00:00.860>two

        00:00:01.599 --> 00:00:02.919
        One two
        three <00:00:02.319>four

        00:00:02.919 --> 00:00:04.000
        three four
        five

        VTT);
});

it('leaves ordinary subtitles untouched', function (): void {
    $vtt = "WEBVTT\n\n00:01.000 --> 00:02.000\nПривет\n\n00:02.500 --> 00:04.000\nКак дела?\nХорошо\n";

    expect(RollUpCaptions::smooth($vtt))->toBe($vtt);
});
