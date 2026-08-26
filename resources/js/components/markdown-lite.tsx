import { Fragment } from 'react';

/**
 * Tiny Markdown renderer — the subset our LLM and changelog actually use:
 *   - `## H2` / `### H3`
 *   - `- bullet` / `* bullet`
 *   - `**bold**`
 *   - `` `code` ``
 *   - blank-line-separated paragraphs
 *
 * Deliberately NOT a CommonMark renderer. Pulling marked + DOMPurify
 * costs ~12 KB gzipped and we render maybe 6 syntax forms; a hand-rolled
 * walker is ~200 LOC and ships zero new bytes. If we ever need tables /
 * links / images, swap this for the full library.
 *
 * Two variants:
 *   - `changelog` — used by the public /changelog page; larger spacing,
 *     slate palette to match marketing site.
 *   - `chat` — used by the playground assistant bubble; tight spacing,
 *     inherits the foreground color so it works in both light + dark.
 */
type Variant = 'changelog' | 'chat';

type Block =
    | { kind: 'heading'; level: 2 | 3; text: string }
    | { kind: 'bullets'; items: string[] }
    | { kind: 'paragraph'; text: string }
    | { kind: 'spacer' };

export function MarkdownLite({
    markdown,
    variant = 'chat',
}: {
    markdown: string;
    variant?: Variant;
}) {
    const blocks = parseMarkdown(markdown);
    const styles = variant === 'changelog' ? CHANGELOG_STYLES : CHAT_STYLES;

    return (
        <>
            {blocks.map((block, i) => {
                if (block.kind === 'spacer') {
                    return null;
                }

                if (block.kind === 'heading') {
                    return block.level === 2 ? (
                        <h3 key={i} className={styles.h2}>
                            {renderInline(block.text, styles)}
                        </h3>
                    ) : (
                        <h4 key={i} className={styles.h3}>
                            {renderInline(block.text, styles)}
                        </h4>
                    );
                }

                if (block.kind === 'bullets') {
                    return (
                        <ul key={i} className={styles.ul}>
                            {block.items.map((item, j) => (
                                <li key={j}>{renderInline(item, styles)}</li>
                            ))}
                        </ul>
                    );
                }

                return (
                    <p key={i} className={styles.p}>
                        {renderInline(block.text, styles)}
                    </p>
                );
            })}
        </>
    );
}

type Styles = {
    h2: string;
    h3: string;
    ul: string;
    p: string;
    code: string;
    bold: string;
};

const CHANGELOG_STYLES: Styles = {
    h2: 'mt-6 text-base font-semibold text-slate-900',
    h3: 'mt-5 text-sm font-semibold text-slate-900',
    ul: 'mt-2 list-disc space-y-1 ps-6 text-[15px] leading-7 text-slate-700',
    p: 'mt-3',
    code: 'rounded bg-slate-100 px-1 font-mono text-[0.9em] text-slate-800',
    bold: 'font-semibold text-slate-900',
};

const CHAT_STYLES: Styles = {
    h2: 'mt-3 first:mt-0 text-sm font-semibold leading-tight',
    h3: 'mt-2 first:mt-0 text-sm font-semibold leading-tight',
    ul: 'my-1 list-disc space-y-0.5 ps-5',
    p: 'mt-1 first:mt-0',
    code: 'rounded bg-foreground/10 px-1 font-mono text-[0.85em]',
    bold: 'font-semibold',
};

function parseMarkdown(markdown: string): Block[] {
    const lines = markdown.replace(/\r\n?/g, '\n').split('\n');
    const blocks: Block[] = [];
    let bullets: string[] | null = null;
    let paragraph: string[] | null = null;

    const flushBullets = () => {
        if (bullets && bullets.length > 0) {
            blocks.push({ kind: 'bullets', items: bullets });
        }

        bullets = null;
    };
    const flushParagraph = () => {
        if (paragraph && paragraph.length > 0) {
            blocks.push({ kind: 'paragraph', text: paragraph.join(' ') });
        }

        paragraph = null;
    };

    for (const raw of lines) {
        const line = raw.trim();

        if (line === '') {
            flushBullets();
            flushParagraph();
            blocks.push({ kind: 'spacer' });
            continue;
        }

        if (line.startsWith('### ')) {
            flushBullets();
            flushParagraph();
            blocks.push({ kind: 'heading', level: 3, text: line.slice(4) });
            continue;
        }

        if (line.startsWith('## ')) {
            flushBullets();
            flushParagraph();
            blocks.push({ kind: 'heading', level: 2, text: line.slice(3) });
            continue;
        }

        if (line.startsWith('- ') || line.startsWith('* ')) {
            flushParagraph();

            if (bullets === null) {
                bullets = [];
            }

            bullets.push(line.slice(2));
            continue;
        }

        flushBullets();

        if (paragraph === null) {
            paragraph = [];
        }

        paragraph.push(line);
    }

    flushBullets();
    flushParagraph();

    return blocks;
}

function renderInline(text: string, styles: Styles) {
    const tokens: Array<string | { kind: 'bold' | 'code'; text: string }> = [];
    let cursor = 0;

    while (cursor < text.length) {
        const remaining = text.slice(cursor);
        const codeMatch = remaining.match(/^`([^`]+)`/);

        if (codeMatch) {
            tokens.push({ kind: 'code', text: codeMatch[1]! });
            cursor += codeMatch[0].length;
            continue;
        }

        const boldMatch = remaining.match(/^\*\*([^*]+)\*\*/);

        if (boldMatch) {
            tokens.push({ kind: 'bold', text: boldMatch[1]! });
            cursor += boldMatch[0].length;
            continue;
        }

        const nextMarker = remaining.search(/`|\*\*/);

        if (nextMarker === -1) {
            tokens.push(remaining);
            cursor = text.length;
            continue;
        }

        tokens.push(remaining.slice(0, nextMarker));
        cursor += nextMarker;
    }

    return tokens.map((token, i) => {
        if (typeof token === 'string') {
            return <Fragment key={i}>{token}</Fragment>;
        }

        if (token.kind === 'code') {
            return (
                <code key={i} className={styles.code}>
                    {token.text}
                </code>
            );
        }

        return (
            <strong key={i} className={styles.bold}>
                {token.text}
            </strong>
        );
    });
}
