import { readZip, writeZip, safeArchivePath } from './offline-zip.ts';
import { sha256 } from './offline-storage.ts';
export type OfflineBlock = {
    id: string;
    type: 'heading' | 'paragraph' | 'list' | 'table';
    text?: string;
    items?: string[];
    headers?: string[];
    rows?: string[][];
    level?: number;
};
export type OfflineMedia = {
    id: string;
    type: string;
    path: string;
    sha256?: string;
    bytes?: number;
    section_id?: string;
    anchor_block_id?: string;
    references?: Array<{
        section_id: string;
        anchor_block_id?: string;
        placement?: string;
    }>;
    [key: string]: unknown;
};
export type OfflineDocument = {
    manifest_id: string;
    title: string;
    subject: string;
    form: number | string;
    language: string;
    overview: {
        summary?: string;
        introduction?: string[];
        goals?: string[];
        practical_guidance?: string;
    };
    sections: Array<{
        id: string;
        title: string;
        criterion_ids: string[];
        blocks: OfflineBlock[];
    }>;
    media: OfflineMedia[];
};
export type OfflineBlob = {
    id: string;
    file: Blob;
    path: string;
    sha256: string;
    mime: string;
};
export type OfflineImport = {
    document: OfflineDocument;
    blobs: OfflineBlob[];
    state?: Record<string, unknown>;
};
function check(valid: unknown, message: string): asserts valid { if (!valid)
    throw new Error(message); }
function strings(value: unknown): value is string[] { return Array.isArray(value) && value.every(v => typeof v === 'string'); }
function json(files: Map<string, Uint8Array>, path: string): Record<string, any> {
    const value = files.get(safeArchivePath(path));
    check(value, `Missing required file: ${path}`);
    check(value.length <= 2 * 1024 * 1024, 'JSON exceeds 2 MiB limit.');
    try {
        const parsed: unknown = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(value));
        check(parsed && typeof parsed === 'object' && !Array.isArray(parsed), 'Expected JSON object.');
        return parsed as Record<string, any>;
    }
    catch {
        throw new Error(`Invalid JSON object: ${path}`);
    }
}
export function mimeForPath(path: string): string { const ext = path.split('.').pop()?.toLowerCase(); return ({ png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp', gif: 'image/gif', svg: 'image/svg+xml', mp4: 'video/mp4', webm: 'video/webm', mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', m4a: 'audio/mp4', vtt: 'text/vtt', srt: 'application/x-subrip', html: 'text/html', htm: 'text/html', txt: 'text/plain', md: 'text/markdown', json: 'application/json' } as Record<string, string>)[ext || ''] || 'application/octet-stream'; }
function cleanOverview(raw: Record<string, unknown> = {}): OfflineDocument['overview'] {
    const overview: OfflineDocument['overview'] = {};
    for (const key of ['summary', 'practical_guidance'] as const) {
        if (raw[key] !== undefined) {
            check(typeof raw[key] === 'string', 'Overview text must be a string.');
            overview[key] = raw[key];
        }
    }
    for (const key of ['introduction', 'goals'] as const) {
        if (raw[key] !== undefined) {
            check(strings(raw[key]), 'Overview items must be strings.');
            overview[key] = raw[key];
        }
    }
    return overview;
}
function cleanBlock(raw: Record<string, any>): OfflineBlock {
    const { id, type } = raw;
    check(['heading', 'paragraph', 'list', 'table'].includes(type), 'Unsupported content block type.');
    const block: OfflineBlock = { id, type };
    if (type === 'heading' || type === 'paragraph') {
        check(typeof raw.text === 'string', 'Block text must be a string.');
        block.text = raw.text;
        if (type === 'heading' && Number.isInteger(raw.level))
            block.level = Math.max(1, Math.min(6, raw.level));
    }
    if (type === 'list') {
        check(strings(raw.items), 'List items must be strings.');
        block.items = raw.items;
    }
    if (type === 'table') {
        check(strings(raw.headers) && raw.headers.length && Array.isArray(raw.rows) && raw.rows.every((row: unknown) => strings(row) && row.length === raw.headers.length), 'Invalid table rows or headers.');
        block.headers = raw.headers;
        block.rows = raw.rows;
    }
    return block;
}
const MEDIA_FIELDS = ['id', 'type', 'path', 'sha256', 'bytes', 'section_id', 'anchor_block_id', 'references', 'placement', 'title', 'caption', 'alt', 'description', 'accessibility', 'term', 'anchor_word', 'aliases', 'required', 'production_status', 'review_status', 'reviewer', 'approved_source_version', 'poster_path', 'caption_path', 'transcript_path'];
function cleanMedia(raw: Record<string, any>): OfflineMedia {
    const media: Record<string, unknown> = {};
    for (const key of MEDIA_FIELDS)
        if (raw[key] !== undefined)
            media[key] = raw[key];
    check(typeof media.type === 'string' && typeof media.path === 'string', 'Media must have a type and path.');
    safeArchivePath(media.path);
    check(!/(^|\/)assessments?\//i.test(media.path), 'Private assessment assets cannot be imported.');
    if (raw.accessibility) {
        const accessibility: Record<string, unknown> = {};
        for (const key of ['description', 'captions_path', 'transcript_path']) {
            if (raw.accessibility[key] !== undefined) {
                check(typeof raw.accessibility[key] === 'string', 'Invalid accessibility metadata.');
                if (key !== 'description')
                    safeArchivePath(raw.accessibility[key]);
                accessibility[key] = raw.accessibility[key];
            }
        }
        for (const prefix of ['captions', 'transcript']) {
            const digest = raw.accessibility[prefix + '_sha256'], bytes = raw.accessibility[prefix + '_bytes'];
            if (digest !== undefined) {
                check(typeof digest === 'string' && /^[a-f0-9]{64}$/i.test(digest), 'Invalid auxiliary SHA-256.');
                accessibility[prefix + '_sha256'] = digest;
            }
            if (bytes !== undefined) {
                check(Number.isSafeInteger(bytes) && bytes >= 0, 'Invalid auxiliary byte count.');
                accessibility[prefix + '_bytes'] = bytes;
            }
        }
        media.accessibility = accessibility;
    }
    return media as OfflineMedia;
}
export function validateDocument(raw: Record<string, any>): OfflineDocument {
    check(typeof raw.manifest_id === 'string' && typeof raw.title === 'string' && Array.isArray(raw.sections) && Array.isArray(raw.media), 'Invalid offline document.');
    const ids = new Set<string>();
    const claim = (id: unknown) => { check(typeof id === 'string' && /^[A-Za-z0-9_.:-]{1,180}$/.test(id) && !ids.has(id), 'IDs must be safe and unique.'); ids.add(id); };
    const sections = raw.sections.map((section: Record<string, any>) => {
        claim(section.id);
        check(typeof section.title === 'string' && strings(section.criterion_ids) && Array.isArray(section.blocks), 'Invalid section.');
        return { id: section.id, title: section.title, criterion_ids: section.criterion_ids, blocks: section.blocks.map((block: Record<string, any>) => { claim(block.id); return cleanBlock(block); }) };
    });
    const media = raw.media.map((item: Record<string, any>) => {
        claim(item.id);
        const cleaned = cleanMedia(item);
        const refs = cleaned.references ?? (cleaned.section_id ? [cleaned] : []);
        check(Array.isArray(refs), 'Invalid media references.');
        for (const reference of refs) {
            const section = sections.find((s: OfflineDocument['sections'][number]) => s.id === reference.section_id);
            check(section && (!reference.anchor_block_id || section.blocks.some((block: OfflineBlock) => block.id === reference.anchor_block_id)), 'Unknown media reference or anchor.');
        }
        return cleaned;
    });
    return { manifest_id: raw.manifest_id, title: raw.title, subject: typeof raw.subject === 'string' ? raw.subject : '', form: typeof raw.form === 'number' || typeof raw.form === 'string' ? raw.form : '', language: typeof raw.language === 'string' ? raw.language : 'en', overview: cleanOverview(raw.overview), sections, media };
}
async function mediaBlobs(document: OfflineDocument, files: Map<string, Uint8Array>, prefix: string): Promise<OfflineBlob[]> {
    const result: OfflineBlob[] = [];
    for (const media of assetEntries(document)) {
        check(!/\.part\d+$|\.\d{3}$/i.test(media.path), 'Multipart media is unsupported. Join the MP4 chunks before importing.');
        const bytes = files.get(prefix + safeArchivePath(media.path));
        check(bytes, `Missing media: ${media.path}`);
        const mime = mimeForPath(media.path), file = new Blob([new Uint8Array(bytes)], { type: mime }), digest = await sha256(file);
        check(media.bytes === undefined || media.bytes === file.size, `Media byte count mismatch: ${media.path}`);
        check(media.sha256 === undefined || media.sha256.toLowerCase() === digest, `Media SHA-256 mismatch: ${media.path}`);
        media.sha256 = digest;
        media.bytes = file.size;
        recordAuxIntegrity(document, media.path, digest, file.size);
        result.push({ id: media.id, path: media.path, file, sha256: digest, mime });
    }
    return result;
}
export async function parseCurriculumFiles(files: Map<string, Uint8Array>): Promise<OfflineImport> {
    check(files.size <= 1500, 'Too many curriculum files.');
    const names = new Set<string>();
    let total = 0;
    for (const [path, data] of files) {
        safeArchivePath(path);
        check(!names.has(path.toLowerCase()), 'Duplicate curriculum file path.');
        names.add(path.toLowerCase());
        total += data.length;
        check(data.length <= 64 * 1024 * 1024 && total <= 256 * 1024 * 1024, 'Curriculum file size limit exceeded.');
    }
    if (files.has('offline-manifest.json'))
        return parseOfflineFiles(files);
    const manifest = json(files, 'lesson-manifest.json');
    check(manifest.schema_version === 'nthanda-pilot-2.0', 'Unsupported curriculum manifest schema.');
    if (manifest.source) {
        const source = files.get(safeArchivePath(manifest.source.path));
        check(source && typeof manifest.source.sha256 === 'string' && await sha256(new Blob([new Uint8Array(source)])) === manifest.source.sha256.toLowerCase(), 'Source document SHA-256 mismatch or missing source.');
    }
    check(Array.isArray(manifest.sections) && Array.isArray(manifest.media), 'Manifest sections and media are required.');
    const sections = [...manifest.sections].sort((a, b) => (a.order || 0) - (b.order || 0)).map(section => {
        const content = json(files, 'dist/' + safeArchivePath(section.content_path));
        check(content.id === section.id && Array.isArray(content.blocks), 'Content identity does not match section.');
        check(strings(section.block_ids) && [...section.block_ids].sort().join('\0') === content.blocks.map((block: Record<string, any>) => block.id).sort().join('\0'), 'Section block references do not match content.');
        return { id: section.id, title: section.title, criterion_ids: section.criterion_ids || [], blocks: [...content.blocks].sort((a, b) => (a.order || 0) - (b.order || 0)) };
    });
    const document = validateDocument({ manifest_id: manifest.lesson_id, title: manifest.title, subject: manifest.syllabus?.subject, form: manifest.syllabus?.form, language: manifest.language, overview: manifest.overview, sections, media: manifest.media });
    await joinVideoChunks(document, files);
    return { document, blobs: await mediaBlobs(document, files, 'dist/') };
}
export async function readCurriculumZip(file: Blob): Promise<OfflineImport> { return parseCurriculumFiles(await readZip(file)); }
function learnerState(state: unknown): Record<string, unknown> | undefined {
    if (!state || typeof state !== 'object' || Array.isArray(state))
        return undefined;
    const clean: Record<string, unknown> = {};
    for (const key of ['answers', 'progress', 'timers', 'section_times', 'bookmarks', 'last_section_id', 'completed_sections', 'notes', 'profile_key', 'base_id', 'base_sha', 'base_token', 'base_document', 'base_progress', 'events', 'journal', 'lesson', 'complete', 'pending_operation', 'pending_uploads'])
        if (Object.hasOwn(state, key))
            clean[key] = (state as Record<string, unknown>)[key];
    return clean;
}
export async function writeOfflineArchive(document: OfflineDocument, files: Map<string, Blob | string | Uint8Array>, state?: unknown): Promise<Blob> {
    const clean = validateDocument(document), entries = new Map<string, Blob | string | Uint8Array>();
    for (const media of assetEntries(clean)) {
        const value = files.get(media.path) ?? files.get(media.id);
        check(value !== undefined, `Missing media: ${media.path}`);
        const file = value instanceof Blob ? value : new Blob([typeof value === 'string' ? value : new Uint8Array(value)]);
        media.sha256 = await sha256(file);
        media.bytes = file.size;
        recordAuxIntegrity(clean, media.path, media.sha256, file.size);
        entries.set('media/' + media.path, file);
    }
    const safeState = learnerState(state);
    const pending = pendingUploads(safeState);
    if (safeState?.pending_uploads) safeState.pending_uploads = pending;
    for (const upload of pending) {
        const value = files.get(upload.archive_path);
        check(value !== undefined, 'Missing pending upload: ' + upload.id);
        const file = value instanceof Blob ? value : new Blob([typeof value === 'string' ? value : new Uint8Array(value)]);
        check(file.size === upload.bytes && await sha256(file) === upload.sha256, 'Pending upload integrity check failed: ' + upload.id);
        const existing = assetEntries(clean).find(asset => asset.id === upload.id);
        check(!existing || existing.sha256 === upload.sha256, 'Pending upload ID conflicts with document media.');
        entries.set(upload.archive_path,file);
    }
    entries.set('offline-manifest.json', JSON.stringify({ schema: 'nthanda-offline-1', document: clean, state: safeState }));
    return writeZip(entries);
}
async function parseOfflineFiles(files: Map<string,Uint8Array>): Promise<OfflineImport> {
    const manifest = json(files,'offline-manifest.json'); check(manifest.schema === 'nthanda-offline-1','Unsupported offline archive schema.');
    const document = validateDocument(manifest.document), blobs = await mediaBlobs(document,files,'media/'), state = learnerState(manifest.state), pending = pendingUploads(state);
    if (state?.pending_uploads) state.pending_uploads = pending;
    for (const upload of pending) {
        const bytes = files.get(upload.archive_path); check(bytes,'Missing pending upload: ' + upload.id);
        const file = new Blob([new Uint8Array(bytes)],{type:upload.mime});
        check(file.size === upload.bytes && await sha256(file) === upload.sha256,'Pending upload integrity check failed: ' + upload.id);
        const existing = blobs.find(blob => blob.id === upload.id);
        check(!existing || existing.sha256 === upload.sha256,'Pending upload ID conflicts with document media.');
        if (!existing) blobs.push({id:upload.id,file,path:upload.path,sha256:upload.sha256,mime:upload.mime});
    }
    return {document,blobs,state};
}
export async function readOfflineArchive(file: Blob): Promise<OfflineImport> { return parseOfflineFiles(await readZip(file)); }
export async function createDocumentFromFiles(files: File[]): Promise<OfflineImport> {
    const document: OfflineDocument = { manifest_id: 'LOCAL-' + crypto.randomUUID(), title: 'Imported lesson', subject: 'General', form: 1, language: 'en', overview: {}, sections: [], media: [] }, blobs: OfflineBlob[] = [], paths = new Set<string>();
    let index = 0;
    for (const file of files) {
        const path = safeArchivePath(file.name);
        check(!/marking|rubric|answer[-_ ]?key|assessment/i.test(path), 'Private marking and assessment files cannot be imported as lesson text.');
        check(!paths.has(path.toLowerCase()), 'Duplicate selected file name.');
        paths.add(path.toLowerCase());
        check(file.size <= 64 * 1024 * 1024, 'File exceeds 64 MiB limit.');
        const id = 'FILE-' + (++index), mime = mimeForPath(path);
        if (/\.(txt|md)$/i.test(path)) {
            document.sections.push({ id, title: path.replace(/\.[^.]+$/, ''), criterion_ids: [`C-${id}`], blocks: (await file.text()).split(/\n\s*\n/).filter(Boolean).map((text, n) => ({ id: `${id}-B${n + 1}`, type: 'paragraph', text })) });
        }
        else {
            check(/^(image|video|audio)\//.test(mime) || ['text/vtt', 'application/x-subrip', 'text/html'].includes(mime), `Unsupported document ${path}. Import text or Markdown, or a curriculum ZIP. PDF and Word parsing are not supported offline.`);
            const digest = await sha256(file);
            document.media.push({ id, type: mime.startsWith('image/') ? 'image' : mime.startsWith('audio/') ? 'audio' : mime.startsWith('video/') ? 'video' : mime === 'text/html' ? 'animation' : 'captions', path, bytes: file.size, sha256: digest, required: true, production_status: 'produced', review_status: 'pending', reviewer: null, approved_source_version: null, placement: 'after', accessibility: {description: path} });
            blobs.push({ id, path, file, sha256: digest, mime });
        }
    }
    check(files.length > 0, 'Select lesson files to import.');
    if (!document.sections.length) { const id = 'SECTION-' + crypto.randomUUID(); document.sections.push({id,title:'Imported media',criterion_ids:[`C-${id}`],blocks:[{id:id + '-B1',type:'paragraph',text:'Explore the attached media.'}]}); }
    for (const section of document.sections) if (!section.blocks.length) section.blocks.push({id:section.id + '-B1',type:'paragraph',text:''});
    const section = document.sections[0];
    for (const media of document.media) { media.section_id = section.id; media.anchor_block_id = section.blocks[0].id; }
    document.title = section.title;
    return { document, blobs };
}
async function joinVideoChunks(document: OfflineDocument, files: Map<string, Uint8Array>): Promise<void> {
    const groups = new Map<string, Array<{
        index: number;
        media: OfflineMedia;
    }>>();
    for (const media of document.media) {
        const match = /^(.*\.mp4)\.part(\d+)$/i.exec(media.path);
        if (match) {
            const group = groups.get(match[1]) || [];
            group.push({ index: Number(match[2]), media });
            groups.set(match[1], group);
        }
    }
    for (const [path, parts] of groups) {
        parts.sort((a, b) => a.index - b.index);
        check(parts.every((part, index) => part.index === index), 'Video chunk sequence must be contiguous from zero.');
        check(!document.media.some(media => media.path === path), 'Combined video path already exists.');
        const chunks: Uint8Array[] = [];
        let size = 0;
        for (const { media } of parts) {
            const bytes = files.get('dist/' + media.path);
            check(bytes, 'Missing video segment: ' + media.path);
            size += bytes.length;
            check(size <= 64 * 1024 * 1024, 'Combined video exceeds 64 MiB limit.');
            check(media.bytes === undefined || media.bytes === bytes.length, 'Video segment byte count mismatch.');
            if (media.sha256)
                check(await sha256(new Blob([new Uint8Array(bytes)])) === media.sha256.toLowerCase(), 'Video segment SHA-256 mismatch.');
            chunks.push(bytes);
        }
        const joined = new Uint8Array(size);
        let offset = 0;
        for (const chunk of chunks) {
            joined.set(chunk, offset);
            offset += chunk.length;
        }
        const view = new DataView(joined.buffer), boxes = new Set<string>();
        offset = 0;
        while (offset < joined.length) {
            check(offset + 8 <= joined.length, 'Truncated reconstructed MP4 box.');
            let length = view.getUint32(offset), header = 8;
            const type = new TextDecoder().decode(joined.subarray(offset + 4, offset + 8));
            if (length === 1) {
                check(offset + 16 <= joined.length, 'Truncated MP4 extended box.');
                const large = view.getBigUint64(offset + 8);
                check(large <= BigInt(Number.MAX_SAFE_INTEGER), 'MP4 box is too large.');
                length = Number(large);
                header = 16;
            }
            else if (length === 0)
                length = joined.length - offset;
            check(length >= header && offset + length <= joined.length, 'Invalid reconstructed MP4 box length.');
            boxes.add(type);
            offset += length;
        }
        check(['ftyp', 'moov', 'mdat'].every(box => boxes.has(box)), 'Reconstructed MP4 must contain ftyp, moov and mdat boxes.');
        const first = parts[0].media;
        first.path = path;
        first.bytes = size;
        first.sha256 = await sha256(new Blob([joined]));
        const removed = new Set(parts.slice(1).map(part => part.media.id));
        document.media = document.media.filter(media => !removed.has(media.id));
        files.set('dist/' + path, joined);
    }
}
function assetEntries(document: OfflineDocument): OfflineMedia[] {
    const assets = [...document.media];
    for (const media of document.media) {
        const accessibility = media.accessibility as Record<string, unknown> | undefined;
        for (const [key, type] of [['captions_path', 'captions'], ['transcript_path', 'transcript']]) {
            const path = accessibility?.[key];
            if (typeof path === 'string' && !assets.some(asset => asset.path === path)) {
                safeArchivePath(path);
                check(!/(^|\/)assessments?\//i.test(path), 'Private assessment files cannot be imported.');
                const prefix = type === 'captions' ? 'captions' : 'transcript';
                assets.push({ id: media.id + ':' + type, type, path, sha256: accessibility?.[prefix + '_sha256'] as string | undefined, bytes: accessibility?.[prefix + '_bytes'] as number | undefined });
            }
        }
    }
    return assets;
}
function recordAuxIntegrity(document: OfflineDocument, path: string, digest: string, bytes: number): void {
    for (const media of document.media) {
        const accessibility = media.accessibility as Record<string, unknown> | undefined;
        if (!accessibility)
            continue;
        for (const prefix of ['captions', 'transcript'])
            if (accessibility[prefix + '_path'] === path) {
                accessibility[prefix + '_sha256'] = digest;
                accessibility[prefix + '_bytes'] = bytes;
            }
    }
}

type PendingUpload = {id:string;path:string;archive_path:string;sha256:string;mime:string;bytes:number};
function pendingUploads(state: Record<string,unknown> | undefined): PendingUpload[] {
    if (state?.pending_uploads === undefined) return [];
    check(Array.isArray(state.pending_uploads) && state.pending_uploads.length <= 1500,'Invalid pending upload list.');
    const ids = new Map<string,string>();
    return state.pending_uploads.map((raw: unknown) => {
        check(raw && typeof raw === 'object' && !Array.isArray(raw),'Invalid pending upload descriptor.');
        const item = raw as PendingUpload;
        check(typeof item.id === 'string' && /^[A-Za-z0-9_.:-]{1,180}$/.test(item.id),'Invalid pending upload ID.');
        check(typeof item.path === 'string' && typeof item.archive_path === 'string','Invalid pending upload path.');
        safeArchivePath(item.path); safeArchivePath(item.archive_path);
        check(typeof item.sha256 === 'string' && /^[a-f0-9]{64}$/.test(item.sha256),'Invalid pending upload SHA-256.');
        check(item.archive_path === 'pending-sync/' + item.sha256 + '.bin','Invalid pending upload archive path.');
        check(Number.isSafeInteger(item.bytes) && item.bytes >= 0 && item.bytes <= 64 * 1024 * 1024,'Invalid pending upload byte count.');
        check(typeof item.mime === 'string' && item.mime.length <= 255 && /^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/i.test(item.mime),'Invalid pending upload MIME.');
        check(!ids.has(item.id) || ids.get(item.id) === item.sha256,'Conflicting duplicate pending upload ID.'); ids.set(item.id,item.sha256);
        return {id:item.id,path:item.path,archive_path:item.archive_path,sha256:item.sha256,mime:item.mime,bytes:item.bytes};
    });
}
