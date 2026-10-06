// Jira's wiki text (REST v2) as parts to render: plain text, links and attachment
// references. Nothing becomes HTML: the page renders each part as text or as a link, so
// a ticket can never inject markup. Only http(s) links are made clickable.

const pattern = new RegExp(
    [
        // [text|https://…]
        String.raw`\[([^\[\]|\n]*)\|(https?:\/\/[^\]\s]+)\]`,
        // [https://…]
        String.raw`\[(https?:\/\/[^\]\s|]+)\]`,
        // [^file.pdf]: an attachment
        String.raw`\[\^([^\]\n]+)\]`,
        // !image.png! or !image.png|thumbnail!: an embedded attachment
        String.raw`!([^!\s|][^!|\n]*?)(?:\|[^!\n]*)?!`,
        // a bare address
        String.raw`(https?:\/\/[^\s<>\[\]|"]+)`,
    ].join('|'),
    'g',
);

const isUrl = (value) => /^https?:\/\/\S+$/i.test(value.trim());

/**
 * @param {string} text
 * @param {(filename: string) => object|null} attachment finds an attachment by its name
 * @returns {Array<{type: 'text', text: string} | {type: 'link', text: string, href: string, via: ?string} | {type: 'file', text: string, file: object}>}
 */
export function jiraParts(text, attachment = () => null) {
    const parts = [];
    let last = 0;
    const plain = (value) => value && parts.push({ type: 'text', text: value });

    for (const match of (text ?? '').matchAll(pattern)) {
        const [whole, label, labelled, bracketed, fileRef, image, bare] = match;
        let part = null;

        if (labelled !== undefined) {
            // A label that is an address itself is where the link means to go; the href is
            // often a mail scanner's wrapper around it (Mimecast and the like). Go where it
            // says, and keep the wrapper for the record.
            const shown = label.trim();
            part = isUrl(shown) && shown !== labelled
                ? { type: 'link', text: shown, href: shown, via: labelled }
                : { type: 'link', text: shown || labelled, href: labelled, via: null };
        } else if (bracketed !== undefined) {
            part = { type: 'link', text: bracketed, href: bracketed, via: null };
        } else if (fileRef !== undefined || image !== undefined) {
            const name = (fileRef ?? image).trim();
            const file = attachment(name);
            part = file ? { type: 'file', text: name, file } : null;
        } else if (bare !== undefined) {
            // A sentence's full stop or closing bracket is not part of the address.
            const trimmed = bare.replace(/[.,;:!?)]+$/, '');
            plain(text.slice(last, match.index));
            parts.push({ type: 'link', text: trimmed, href: trimmed, via: null });
            last = match.index + trimmed.length;
            continue;
        }

        if (part === null) {
            continue;
        }
        plain(text.slice(last, match.index));
        parts.push(part);
        last = match.index + whole.length;
    }
    plain((text ?? '').slice(last));

    return parts;
}
