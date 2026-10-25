const MiB = 1024 * 1024;
const MAX_ARCHIVE = 128 * MiB, MAX_TOTAL = 256 * MiB, MAX_FILE = 64 * MiB, MAX_ENTRIES = 1500;
export function safeArchivePath(path: string): string {
    if (!path || path.length > 399 || /[\\:\x00-\x1f?#<>"|*]/.test(path) || path.startsWith('/') || path.split('/').some(p => !p || p === '.' || p === '..' || /[ .]$/.test(p) || /^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i.test(p)))
        throw new Error('Unsafe archive path: ' + path);
    return path;
}
export function crc32(data: Uint8Array): number {
    let crc = 0xffffffff;
    for (const byte of data) {
        crc ^= byte;
        for (let bit = 0; bit < 8; bit++)
            crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
    }
    return (crc ^ 0xffffffff) >>> 0;
}
function check(valid: boolean, message: string): asserts valid { if (!valid)
    throw new Error(message); }
export async function readZip(file: Blob): Promise<Map<string, Uint8Array>> {
    check(file.size <= MAX_ARCHIVE, 'ZIP exceeds 128 MiB compressed limit.');
    const bytes = new Uint8Array(await file.arrayBuffer()), view = new DataView(bytes.buffer);
    const u16 = (n: number) => view.getUint16(n, true), u32 = (n: number) => view.getUint32(n, true);
    let end = -1;
    for (let n = bytes.length - 22; n >= Math.max(0, bytes.length - 65557); n--) {
        if (u32(n) === 0x06054b50 && n + 22 + u16(n + 20) === bytes.length) {
            end = n;
            break;
        }
    }
    check(end >= 0, 'Invalid ZIP end record.');
    const count = u16(end + 10), centralSize = u32(end + 12), centralOffset = u32(end + 16);
    check(u16(end + 4) === 0 && u16(end + 6) === 0 && u16(end + 8) === count, 'Multipart ZIP is unsupported.');
    check(count <= MAX_ENTRIES && count !== 65535 && centralSize !== 0xffffffff && centralOffset !== 0xffffffff, 'ZIP entry limit or ZIP64 unsupported.');
    check(centralOffset + centralSize === end, 'Invalid ZIP central directory length.');
    const result = new Map<string, Uint8Array>(), names = new Set<string>(), ranges: Array<[
        number,
        number
    ]> = [];
    let cursor = centralOffset, total = 0;
    for (let index = 0; index < count; index++) {
        check(cursor + 46 <= end && u32(cursor) === 0x02014b50, 'Invalid ZIP central entry.');
        const flags = u16(cursor + 8), method = u16(cursor + 10), crc = u32(cursor + 16), compressed = u32(cursor + 20), expanded = u32(cursor + 24), nameLength = u16(cursor + 28), extraLength = u16(cursor + 30), commentLength = u16(cursor + 32), local = u32(cursor + 42);
        check(cursor + 46 + nameLength + extraLength + commentLength <= end, 'Truncated ZIP entry.');
        check(!(flags & (1 | 64)) && (method === 0 || method === 8), 'Encrypted or unsupported ZIP compression.');
        check(u16(cursor + 34) === 0 && compressed !== 0xffffffff && expanded <= MAX_FILE && local !== 0xffffffff, 'ZIP64 or oversized entry unsupported.');
        check(((u32(cursor + 38) >>> 16) & 0xf000) !== 0xa000, 'ZIP symlinks are forbidden.');
        const name = new TextDecoder('utf-8', { fatal: true }).decode(bytes.subarray(cursor + 46, cursor + 46 + nameLength));
        const directory = name.endsWith('/');
        safeArchivePath(directory ? name.slice(0, -1) : name);
        check(!names.has(name.toLowerCase()), 'Duplicate archive path.');
        names.add(name.toLowerCase());
        for (let x = cursor + 46 + nameLength; x < cursor + 46 + nameLength + extraLength;) {
            check(x + 4 <= cursor + 46 + nameLength + extraLength, 'Invalid ZIP extra field.');
            const size = u16(x + 2);
            check(u16(x) !== 1 && x + 4 + size <= cursor + 46 + nameLength + extraLength, 'ZIP64 or invalid extra field.');
            x += 4 + size;
        }
        check(local + 30 <= centralOffset && u32(local) === 0x04034b50, 'Invalid ZIP local header.');
        const localNameLength = u16(local + 26), localExtra = u16(local + 28), start = local + 30 + localNameLength + localExtra, finish = start + compressed;
        check(u16(local + 6) === flags && u16(local + 8) === method && localNameLength === nameLength && bytes.subarray(local + 30, local + 30 + localNameLength).every((b, i) => b === bytes[cursor + 46 + i]), 'ZIP local entry differs from directory.');
        check(finish <= centralOffset && !ranges.some(([a, b]) => local < b && finish > a), 'Overlapping or truncated ZIP entry.');
        let rangeEnd = finish;
        if (flags & 8) {
            const descriptor = finish + (finish + 4 <= centralOffset && u32(finish) === 0x08074b50 ? 4 : 0);
            check(descriptor + 12 <= centralOffset && u32(descriptor) === crc && u32(descriptor + 4) === compressed && u32(descriptor + 8) === expanded, 'Invalid or missing ZIP data descriptor.');
            rangeEnd = descriptor + 12;
            check(!ranges.some(([a, b]) => local < b && rangeEnd > a), 'Overlapping ZIP data descriptor.');
        }
        ranges.push([local, rangeEnd]);
        if (!(flags & 8))
            check(u32(local + 14) === crc && u32(local + 18) === compressed && u32(local + 22) === expanded, 'ZIP local lengths differ.');
        total += expanded;
        check(total <= MAX_TOTAL, 'ZIP exceeds expanded size limit.');
        let data = bytes.slice(start, finish);
        if (method === 8) {
            let stream: DecompressionStream;
            try {
                stream = new DecompressionStream('deflate-raw');
            }
            catch {
                throw new Error('This browser cannot open compressed ZIPs. Use current Android Chromium or an uncompressed ZIP.');
            }
            const reader = new Blob([data]).stream().pipeThrough(stream).getReader(), chunks: Uint8Array[] = [];
            let length = 0;
            try {
                while (true) {
                    const part = await reader.read();
                    if (part.done)
                        break;
                    length += part.value.length;
                    if (length > expanded || length > MAX_FILE) {
                        await reader.cancel();
                        throw new Error('ZIP decompression exceeds declared size.');
                    }
                    chunks.push(part.value);
                }
            }
            finally {
                reader.releaseLock();
            }
            data = new Uint8Array(length);
            let offset = 0;
            for (const chunk of chunks) {
                data.set(chunk, offset);
                offset += chunk.length;
            }
        }
        check(data.length === expanded, 'ZIP expanded length mismatch.');
        check(crc32(data) === crc, 'ZIP CRC checksum mismatch.');
        if (!directory)
            result.set(name, data);
        cursor += 46 + nameLength + extraLength + commentLength;
    }
    check(cursor === end, 'ZIP central directory entry count mismatch.');
    return result;
}
export async function writeZip(files: Map<string, Blob | string | Uint8Array>): Promise<Blob> {
    check(files.size <= MAX_ENTRIES, 'Too many ZIP entries.');
    const parts: BlobPart[] = [], central: Uint8Array<ArrayBuffer>[] = [], names = new Set<string>();
    let offset = 0, total = 0;
    for (const [path, value] of files) {
        safeArchivePath(path);
        check(!names.has(path.toLowerCase()), 'Duplicate archive path.');
        names.add(path.toLowerCase());
        const name = new TextEncoder().encode(path), data = typeof value === 'string' ? new TextEncoder().encode(value) : value instanceof Blob ? new Uint8Array(await value.arrayBuffer()) : value;
        total += data.length;
        check(data.length <= MAX_FILE && total <= MAX_TOTAL, 'ZIP expanded size limit exceeded.');
        const crc = crc32(data), local = new Uint8Array(30 + name.length), lv = new DataView(local.buffer), directory = new Uint8Array(46 + name.length), cv = new DataView(directory.buffer);
        lv.setUint32(0, 0x04034b50, true);
        lv.setUint16(4, 20, true);
        lv.setUint16(6, 0x800, true);
        lv.setUint32(14, crc, true);
        lv.setUint32(18, data.length, true);
        lv.setUint32(22, data.length, true);
        lv.setUint16(26, name.length, true);
        local.set(name, 30);
        cv.setUint32(0, 0x02014b50, true);
        cv.setUint16(4, 20, true);
        cv.setUint16(6, 20, true);
        cv.setUint16(8, 0x800, true);
        cv.setUint32(16, crc, true);
        cv.setUint32(20, data.length, true);
        cv.setUint32(24, data.length, true);
        cv.setUint16(28, name.length, true);
        cv.setUint32(42, offset, true);
        directory.set(name, 46);
        parts.push(local, new Uint8Array(data));
        central.push(directory);
        offset += local.length + data.length;
    }
    const size = central.reduce((sum, p) => sum + p.length, 0), end = new Uint8Array(22), ev = new DataView(end.buffer);
    check(offset + size + 22 <= MAX_ARCHIVE, 'ZIP exceeds 128 MiB archive limit.');
    ev.setUint32(0, 0x06054b50, true);
    ev.setUint16(8, files.size, true);
    ev.setUint16(10, files.size, true);
    ev.setUint32(12, size, true);
    ev.setUint32(16, offset, true);
    return new Blob([...parts, ...central, end], { type: 'application/zip' });
}
