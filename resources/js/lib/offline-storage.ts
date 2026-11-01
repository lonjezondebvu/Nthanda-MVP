export type OfflineStore = 'profiles' | 'drafts' | 'blobs';
function openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        if (typeof indexedDB === 'undefined') {
            reject(new Error('Offline storage is unavailable. Use a supported browser outside private mode.'));
            return;
        }
        const request = indexedDB.open('nthanda-offline', 1);
        let failed = false;
        request.onupgradeneeded = () => { for (const name of ['profiles', 'drafts', 'blobs'])
            if (!request.result.objectStoreNames.contains(name))
                request.result.createObjectStore(name); };
        request.onblocked = () => { failed = true; reject(new Error('Close other Nthanda tabs to upgrade offline storage.')); };
        request.onerror = () => reject(request.error ?? new Error('Could not open offline storage.'));
        request.onsuccess = () => { if (failed) {
            request.result.close();
            return;
        } request.result.onversionchange = () => request.result.close(); resolve(request.result); };
    });
}
async function operation<T>(store: OfflineStore, mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(store, mode);
        let result: T;
        const request = action(transaction.objectStore(store));
        request.onsuccess = () => { result = request.result; };
        transaction.oncomplete = () => { db.close(); resolve(result); };
        transaction.onabort = transaction.onerror = () => { db.close(); reject(transaction.error ?? request.error ?? new Error('Offline storage write failed. Check free storage.')); };
    });
}
export const getRecord = <T>(store: OfflineStore, key: string): Promise<T | undefined> => operation(store, 'readonly', s => s.get(key));
export const putRecord = (store: OfflineStore, key: string, value: unknown): Promise<IDBValidKey> => operation(store, 'readwrite', s => s.put(value, key));
export const listRecords = <T>(store: OfflineStore): Promise<T[]> => operation(store, 'readonly', s => s.getAll());
export const deleteRecord = (store: OfflineStore, key: string): Promise<undefined> => operation(store, 'readwrite', s => s.delete(key));
export async function sha256(blob: Blob): Promise<string> { return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await blob.arrayBuffer())), b => b.toString(16).padStart(2, '0')).join(''); }
export type OfflineFile = {
    url: string;
    path: string;
    mime: string;
    bytes: number;
    sha256?: string;
};
export async function cacheFiles(profileKey: string, files: OfflineFile[], includeMedia: boolean | 'text' | 'audio' | 'full', onProgress: (done: number, total: number, message: string) => void): Promise<Array<OfflineFile & {
    localUrl: string;
}>> {
    if (typeof caches === 'undefined')
        throw new Error('Media storage is unavailable. Use current Android Chromium over HTTPS.');
    let cache: Cache;
    try {
        cache = await caches.open('nthanda-media-v1');
    }
    catch {
        throw new Error('Could not open media storage. Check free space and browser storage settings.');
    }
    const selected = files.filter(file => includeMedia === true || includeMedia === 'full' || (includeMedia === 'audio' ? !/^video\//i.test(file.mime) : !/^(video|audio)\//i.test(file.mime))), result: Array<OfflineFile & {
        localUrl: string;
    }> = [];
    for (const file of selected) {
        const url = new URL(file.url, location.origin);
        if (url.origin !== location.origin || !/^\/lessons\/\d+\/assets\/[a-f0-9]{64}$/.test(url.pathname) || url.search || url.hash)
            throw new Error('Only published same-origin lesson assets can be saved offline.');
        if (!Number.isSafeInteger(file.bytes) || file.bytes < 0 || (file.sha256 && !/^[a-f0-9]{64}$/i.test(file.sha256)))
            throw new Error('Invalid offline asset integrity metadata.');
        const key = `/offline-media/${encodeURIComponent(profileKey)}/${file.sha256 || await sha256(new Blob([url.href]))}`;
        const valid = async (response: Response): Promise<boolean> => {
            if (!response.ok || response.headers.get('Content-Type')?.split(';')[0].trim().toLowerCase() !== file.mime.split(';')[0].trim().toLowerCase())
                return false;
            const blob = await response.blob();
            return blob.size === file.bytes && (!file.sha256 || await sha256(blob) === file.sha256.toLowerCase());
        };
        let response = await cache.match(key);
        if (!response || !await valid(response.clone())) {
            await cache.delete(key);
            response = await fetch(url.href, { credentials: 'same-origin' });
            if (!await valid(response.clone()))
                throw new Error(`Download integrity check failed: ${file.path}`);
            const headers = new Headers(response.headers);
            headers.set('Content-Security-Policy', "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; media-src data: blob:");
            headers.set('X-Content-Type-Options', 'nosniff');
            await cache.put(key, new Response(await response.blob(), { status: 200, headers }));
        }
        result.push({ ...file, localUrl: key });
        onProgress(result.length, selected.length, `Saved ${file.path}`);
    }
    return result;
}
