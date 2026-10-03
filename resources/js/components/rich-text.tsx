import { Fragment, type ReactNode } from 'react';

// Ссылки и таймкоды (1:23, 01:02:03) в описании.
const TOKEN = /(https?:\/\/[^\s<>"')\]]+)|(?<![\d:])((?:\d{1,2}:)?\d{1,2}:\d{2})(?![\d:])/g;

function toSeconds(timecode: string): number {
    return timecode.split(':').reduce((total, part) => total * 60 + Number(part), 0);
}

export function RichText({ text, onSeek }: { text: string; onSeek?: (seconds: number) => void }) {
    const nodes: ReactNode[] = [];
    let last = 0;

    for (const match of text.matchAll(TOKEN)) {
        const index = match.index ?? 0;
        nodes.push(text.slice(last, index));

        if (match[1]) {
            nodes.push(
                <a key={index} href={match[1]} target="_blank" rel="noreferrer noopener" className="break-all text-sky-600 hover:underline dark:text-sky-400">
                    {match[1]}
                </a>,
            );
        } else if (onSeek) {
            nodes.push(
                <button key={index} type="button" onClick={() => onSeek(toSeconds(match[2]))} className="font-medium text-sky-600 tabular-nums hover:underline dark:text-sky-400">
                    {match[2]}
                </button>,
            );
        } else {
            nodes.push(match[2]);
        }

        last = index + match[0].length;
    }
    nodes.push(text.slice(last));

    return <Fragment>{nodes}</Fragment>;
}
